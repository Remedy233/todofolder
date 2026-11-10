<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\TodoFolder\Db\Task;
use OCA\TodoFolder\Db\Workspace;
use OCP\Files\File;
use OCP\IL10N;
use OCP\Files\Folder;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * One-way bridge into Deck: a task becomes a card, and later analyses update that same
 * card. Nothing flows back — Deck stays the place you look at, TodoFolder stays the place
 * that decides.
 *
 * What lands on the card, in Deck's own vocabulary:
 * - title / description / due date / colour (priority) — always
 * - labels — the AI tags, created per board if missing
 * - assignee — the workspace owner
 * - material links — the task folder and its files as markdown links to /f/<fileid>,
 *   which is Nextcloud's own "open in Files" route. Deck's attachment API only accepts
 *   uploads (type `deck_file`) or a request upload (type `file`), so a link is the honest
 *   way to point at files that already live in Files instead of copying them.
 *
 * Deck's services are not public API, so every call is behind class_exists() and a catch:
 * with Deck missing or a changed signature, tasks still analyse and the Deck error is
 * reported on the task instead of taking the whole run down.
 *
 * Permission checks inside Deck are made against the session user, which is why the
 * bridge impersonates the board owner for the duration of the call — required in a
 * background job, harmless in a request (the owner usually *is* the caller).
 */
class DeckBridge {
	public function __construct(
		private ConfigService $config,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private WorkspaceService $workspaces,
		private IURLGenerator $url,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
	}

	public function isAvailable(): bool {
		return $this->config->deckEnabled()
			&& class_exists('OCA\Deck\Service\CardService')
			&& class_exists('OCA\Deck\Db\StackMapper');
	}

	/**
	 * Boards the given user can see, for the workspace settings dropdown.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public function boards(string $userId): array {
		if (!$this->isAvailable()) {
			return [];
		}
		return $this->asUser($userId, function () use ($userId): array {
			$boardService = Server::get('OCA\Deck\Service\BoardService');
			$out = [];
			foreach ($boardService->findAll() as $board) {
				$out[] = ['id' => (int)$board->getId(), 'title' => (string)$board->getTitle()];
			}
			usort($out, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));
			return $out;
		}) ?? [];
	}

	/**
	 * Make sure the user has a board and a column to receive cards, and return the ids.
	 *
	 * This is what "一键准备看板" calls: it reuses the preferred board when one is given,
	 * otherwise a board with the same title, otherwise it creates one — and it always
	 * ends with a usable stack, because a board without columns silently swallows cards.
	 *
	 * @return array{boardId: int, stackId: int, createdBoard: bool, createdStack: bool}
	 * @throws \RuntimeException when Deck is unavailable or refuses the operation
	 */
	public function ensureBoardAndStack(string $userId, string $boardTitle, string $stackTitle, ?int $preferredBoardId = null): array {
		if (!$this->isAvailable()) {
			throw new \RuntimeException($this->l10n->t('Deck is unavailable: the app is not installed, or Deck sync is switched off in the TodoFolder settings'));
		}

		$result = $this->asUser($userId, function () use ($userId, $boardTitle, $stackTitle, $preferredBoardId): array {
			$boardService = Server::get('OCA\Deck\Service\BoardService');
			$stackService = Server::get('OCA\Deck\Service\StackService');
			$stackMapper = Server::get('OCA\Deck\Db\StackMapper');

			$boardId = $preferredBoardId;
			$createdBoard = false;

			// Deck seeds every new user with a demo board. Dropping real task cards into
			// "Welcome to Nextcloud Deck!" is worse than creating a proper one, so a
			// preferred board pointing at the demo is ignored.
			if ($boardId !== null) {
				try {
					$preferred = $boardService->find($boardId, false);
					if (str_starts_with((string)$preferred->getTitle(), 'Welcome to Nextcloud Deck')) {
						$boardId = null;
					}
				} catch (\Throwable) {
					$boardId = null;
				}
			}

			if ($boardId === null) {
				foreach ($boardService->findAll() as $board) {
					if ((string)$board->getTitle() === $boardTitle) {
						$boardId = (int)$board->getId();
						break;
					}
				}
			}
			if ($boardId === null) {
				$board = $boardService->create($boardTitle, $userId, '0082c9');
				$boardId = (int)$board->getId();
				$createdBoard = true;
			}

			$stacks = $stackMapper->findAll($boardId);
			foreach ($stacks as $stack) {
				if ((string)$stack->getTitle() === $stackTitle) {
					return [
						'boardId' => $boardId,
						'stackId' => (int)$stack->getId(),
						'createdBoard' => $createdBoard,
						'createdStack' => false,
					];
				}
			}

			$stack = $stackService->create($stackTitle, $boardId, count($stacks));
			return [
				'boardId' => $boardId,
				'stackId' => (int)$stack->getId(),
				'createdBoard' => $createdBoard,
				'createdStack' => true,
			];
		});

		if ($result === null) {
			throw new \RuntimeException($this->l10n->t('Deck refused the operation (unknown user %s)', [$userId]));
		}
		return $result;
	}

	/**
	 * @return array<int, array{id: int, title: string}>
	 */
	public function stacks(string $userId, int $boardId): array {
		if (!$this->isAvailable()) {
			return [];
		}
		return $this->asUser($userId, function () use ($boardId): array {
			/** @var \OCA\Deck\Db\StackMapper $stackMapper */
			$stackMapper = Server::get('OCA\Deck\Db\StackMapper');
			$out = [];
			foreach ($stackMapper->findAll($boardId) as $stack) {
				$out[] = ['id' => (int)$stack->getId(), 'title' => (string)$stack->getTitle()];
			}
			return $out;
		}) ?? [];
	}

	/**
	 * Create the card for a task, or update the one created earlier.
	 *
	 * Returns `cardId` plus whether Deck considers the card finished (moved out of the bound
	 * column, marked done or archived), or null when Deck is unavailable or the workspace has
	 * no board configured. The caller turns that flag into the task status: Deck owns
	 * completion, this app only mirrors it.
	 *
	 * @param string[] $tagNames the task's tags; they become Deck labels on this board
	 * @return array{cardId: int, finished: bool}|null
	 * @throws \RuntimeException when Deck itself refuses the operation
	 */
	public function sync(Task $task, Workspace $workspace, array $tagNames = []): ?array {
		if (!$this->isAvailable()) {
			return null;
		}
		$boardId = $workspace->getDeckBoardId();
		// New cards land in the inbox column; every write after that respects where the card
		// actually is, which is what task.deckStackId records.
		$stackId = $workspace->getDeckInboxStackId() ?? $workspace->getDeckStackId();
		$homeStack = $task->getDeckStackId() ?? $stackId;
		if ($boardId === null || $stackId === null || $homeStack === null) {
			return null;
		}

		$owner = $workspace->getUserId();
		// Deck's services capture the acting user when they are constructed, which a
		// background job or an occ run cannot influence: there is no session at all, so
		// every service call is refused with "Permission denied". Those code paths write
		// through the mappers instead and skip the extras that only make sense in a
		// request (labels, assignment, attachments are refreshed by the web UI path).
		$hasSession = $this->userSession->getUser() !== null;

		return $this->asUser($owner, function () use ($task, $workspace, $stackId, $homeStack, $boardId, $owner, $tagNames, $hasSession): ?array {
			/** @var \OCA\Deck\Service\CardService $cardService */
			$cardService = $hasSession ? Server::get('OCA\Deck\Service\CardService') : null;

			$title = $task->getTitle();
			if ($title === null || $title === '') {
				$title = $task->getFolderName();
			}
			$description = $this->description($task, $workspace);
			$due = $task->getDueDate();
			// The received date is Deck's start date: one date flows into the other.
			$start = $task->getReceivedAt();
			$color = $this->colorFor($task->getPriority());

			$cardId = null;
			$finishedByHuman = false;
			$existingCardId = $task->getDeckCardId();
			if ($existingCardId !== null) {
				try {
					$card = $cardService !== null
						? $cardService->find($existingCardId)
						: Server::get(CardMapper::class)->find($existingCardId, false);

					// Deck owns placement and completion: once a card leaves the bound
					// stack — dragged to "Done", archived, marked done — a later scan or
					// re-analysis must not drag it back. Only cards that are still where
					// this app put them get refreshed.
					$movedAway = (int)$card->getStackId() !== (int)$homeStack;
					$done = $card->getDone();
					$finished = $movedAway || $card->getArchived() || $done !== null;
					if ($finished) {
						$this->logger->info(
							'todofolder: deck card ' . $existingCardId . ' is finished or moved '
							. '(stack ' . (int)$card->getStackId() . ' vs home ' . (int)$homeStack
							. ', done=' . ($done === null ? 'no' : $done->format('c'))
							. ', archived=' . ($card->getArchived() ? 'yes' : 'no')
							. '), leaving it alone',
						);
						$cardId = $existingCardId;
						$finishedByHuman = true;
					} elseif ($cardService !== null) {
						$cardService->update(
							$existingCardId,
							$title,
							(int)$card->getStackId(),
							'plain',
							$owner,
							$description,
							(int)$card->getOrder(),
							$due,
							(int)$card->getDeletedAt(),
							$card->getArchived(),
							// Deck's `done` is an OptionalNullableValue; null means "leave it
							// as it is", which is exactly what a refresh should do.
							null,
							$start,
						);
						$cardId = $existingCardId;
					} else {
						$card->setTitle($title);
						$card->setDescription($description);
						$card->setDuedate($due);
						$card->setStartdate($start);
						$card->setColor($color);
						$card->setLastEditor($owner);
						$card->setLastModified(time());
						Server::get(CardMapper::class)->update($card);
						$cardId = $existingCardId;
					}
				} catch (\Throwable $e) {
					// The card was deleted in Deck: fall through and create a new one.
					$this->logger->info('todofolder: deck card ' . $existingCardId . ' is gone (' . $e->getMessage() . '), recreating');
				}
			}

			if ($cardId === null) {
				if ($cardService !== null) {
					$card = $cardService->create($title, (int)$homeStack, 'plain', 0, $owner, $description, $due, $start, $color);
					$cardId = (int)$card->getId();
				} else {
					$card = new Card();
					$card->setTitle($title);
					$card->setDescription($description);
					$card->setStackId((int)$homeStack);
					$card->setType('plain');
					$card->setOwner($owner);
					$card->setLastEditor($owner);
					$card->setOrder(0);
					$card->setArchived(false);
					$card->setDeletedAt(0);
					$card->setDuedate($due);
					$card->setStartdate($start);
					$card->setColor($color);
					$cardId = (int)Server::get(CardMapper::class)->insert($card)->getId();
				}
			}

			// Deck-native fields beyond title/description/due/colour. They go through Deck's
			// services, which only work with a request session; a background run leaves
			// them for the next web-triggered sync instead of failing the whole task.
			if ($cardService !== null) {
				$this->syncLabels($cardId, (int)$boardId, $tagNames);
				$this->assignOwner($cardId, $owner);
				$this->attachMaterials($cardId, $task, $workspace, $owner);
			} else {
				$this->logger->debug('todofolder: background sync of card ' . $cardId . ' skipped labels, assignee and attachments');
			}

			$finalStack = (int)($card->getStackId() ?? $homeStack);
			return ['cardId' => (int)$cardId, 'finished' => $finishedByHuman, 'stackId' => $finalStack];
		});
	}

	/**
	 * Delete the card that belongs to a task. Used when a user removes a task record and
	 * explicitly asks for the card to go too; the default keeps the card.
	 */
	public function deleteCard(Task $task, Workspace $workspace): bool {
		$cardId = $task->getDeckCardId();
		if ($cardId === null || !$this->isAvailable()) {
			return false;
		}
		$owner = $workspace->getUserId();
		$hasSession = $this->userSession->getUser() !== null;

		return (bool)$this->asUser($owner, function () use ($cardId, $hasSession, $owner): bool {
			if ($hasSession) {
				/** @var \OCA\Deck\Service\CardService $cardService */
				$cardService = Server::get('OCA\Deck\Service\CardService');
				$cardService->delete($cardId);
				return true;
			}
			$card = Server::get(CardMapper::class)->find($cardId, false);
			$card->setDeletedAt(time());
			$card->setLastEditor($owner);
			$card->setLastModified(time());
			Server::get(CardMapper::class)->update($card);
			return true;
		}) ?? false;
	}

	/**
	 * Move an existing card into another column — the way "start", "finish" and a manual
	 * category change reach Deck. Works from the web path (Deck's service) and from occ or a
	 * background job (the mapper), because Deck has no webhook and both paths have to exist.
	 */
	public function moveCard(Task $task, Workspace $workspace, int $stackId): bool {
		if (!$this->isAvailable()) {
			return false;
		}
		$cardId = $task->getDeckCardId();
		$boardId = $workspace->getDeckBoardId();
		if ($cardId === null || $boardId === null) {
			return false;
		}
		$owner = $workspace->getUserId();
		$hasSession = $this->userSession->getUser() !== null;

		return (bool)$this->asUser($owner, function () use ($cardId, $stackId, $task, $owner, $hasSession): bool {
			$title = $task->getTitle();
			if ($title === null || $title === '') {
				$title = $task->getFolderName();
			}
			if ($hasSession) {
				/** @var \OCA\Deck\Service\CardService $cardService */
				$cardService = Server::get('OCA\Deck\Service\CardService');
				$card = $cardService->find($cardId);
				$cardService->update(
					$cardId,
					$title,
					$stackId,
					'plain',
					$owner,
					(string)$card->getDescription(),
					0,
					$task->getDueDate(),
					(int)$card->getDeletedAt(),
					$card->getArchived(),
					null,
					$task->getReceivedAt(),
				);
				return true;
			}

			$card = Server::get(CardMapper::class)->find($cardId, false);
			$card->setStackId($stackId);
			$card->setOrder(0);
			$card->setLastEditor($owner);
			$card->setLastModified(time());
			Server::get(CardMapper::class)->update($card);
			return true;
		}) ?? false;
	}

	/**
	 * Attach the task folder to the card the way Deck itself does it: a share of type
	 * DECK whose share_with is the card id. Deck's attachment list is built from those
	 * shares (FilesAppService::listAttachments), so the folder shows up in the card's
	 * attachment area — the files are linked, not copied.
	 *
	 * The service that would normally do this (FilesAppService) only accepts an uploaded
	 * file, so the share is created directly; the table it would have written to does not
	 * exist for this type, the share *is* the attachment.
	 */
	private function attachMaterials(int $cardId, Task $task, Workspace $workspace, string $owner): void {
		if (!$this->config->deckAttachFolder()) {
			return;
		}
		$folder = $this->workspaces->resolveTaskFolder($workspace, $task->getFolderPath());
		if (!$folder instanceof Folder) {
			return;
		}

		try {
			/** @var \OCP\Share\IManager $shareManager */
			$shareManager = Server::get(\OCP\Share\IManager::class);
			/** @var \OCP\IDBConnection $db */
			$db = Server::get(\OCP\IDBConnection::class);

			// One attachment per card+folder: re-analysing must not pile up shares.
			$qb = $db->getQueryBuilder();
			$qb->select($qb->func()->count('id'))
				->from('share')
				->where($qb->expr()->eq('share_type', $qb->createNamedParameter(\OCP\Share\IShare::TYPE_DECK, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter((string)$cardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter($folder->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$existing = (int)$result->fetchOne();
			$result->closeCursor();
			if ($existing > 0) {
				return;
			}

			$share = $shareManager->newShare();
			$share->setNode($folder);
			$share->setShareType(\OCP\Share\IShare::TYPE_DECK);
			$share->setSharedWith((string)$cardId);
			$share->setPermissions(\OCP\Constants::PERMISSION_READ);
			$share->setSharedBy($owner);
			$shareManager->createShare($share);
		} catch (\Throwable $e) {
			// An attachment is a convenience: the analysis and the card stay valid without it.
			$this->logger->info('todofolder: cannot attach folder to card ' . $cardId . ': ' . $e->getMessage());
		}
	}

	/**
	 * The AI tags become Deck labels on this board, and get attached to the card.
	 * Labels are board-scoped in Deck, so a tag that exists on the board is reused.
	 *
	 * @param string[] $tagNames
	 */
	private function syncLabels(int $cardId, int $boardId, array $tagNames): void {
		if (!$this->config->deckSyncLabels() || $tagNames === []) {
			return;
		}

		try {
			/** @var \OCA\Deck\Db\LabelMapper $labelMapper */
			$labelMapper = Server::get('OCA\Deck\Db\LabelMapper');
			/** @var \OCA\Deck\Service\LabelService $labelService */
			$labelService = Server::get('OCA\Deck\Service\LabelService');
			/** @var \OCA\Deck\Service\CardService $cardService */
			$cardService = Server::get('OCA\Deck\Service\CardService');
		} catch (\Throwable $e) {
			$this->logger->debug('todofolder: deck label services unavailable: ' . $e->getMessage());
			return;
		}

		$alreadyOnCard = [];
		foreach ($labelMapper->findAssignedLabelsForCard($cardId) as $label) {
			$alreadyOnCard[(string)$label->getTitle()] = true;
		}

		$onBoard = [];
		foreach ($labelMapper->findAll($boardId) as $label) {
			$onBoard[(string)$label->getTitle()] = (int)$label->getId();
		}

		foreach (array_slice($tagNames, 0, 6) as $name) {
			if (isset($alreadyOnCard[$name])) {
				continue;
			}
			try {
				$labelId = $onBoard[$name] ?? null;
				if ($labelId === null) {
					$label = $labelService->create($name, $this->colorForTag($name), $boardId);
					$labelId = (int)$label->getId();
					$onBoard[$name] = $labelId;
				}
				$cardService->assignLabel($cardId, $labelId);
			} catch (\Throwable $e) {
				$this->logger->debug('todofolder: cannot attach label ' . $name . ': ' . $e->getMessage());
			}
		}

		// The card must mirror the task's tags, not accumulate them: a label whose tag is
		// gone from the task is removed again, so editing "合同, 法务" in TodoFolder really
		// does leave just those two on the card.
		if (!$this->config->deckPruneLabels()) {
			return;
		}
		foreach ($labelMapper->findAssignedLabelsForCard($cardId) as $label) {
			if (in_array((string)$label->getTitle(), $tagNames, true)) {
				continue;
			}
			try {
				$cardService->removeLabel($cardId, (int)$label->getId());
			} catch (\Throwable $e) {
				$this->logger->debug('todofolder: cannot remove label ' . $label->getTitle() . ': ' . $e->getMessage());
			}
		}
	}

	private function assignOwner(int $cardId, string $userId): void {
		if (!$this->config->deckAssignOwner()) {
			return;
		}
		try {
			/** @var \OCA\Deck\Service\AssignmentService $assignments */
			$assignments = Server::get('OCA\Deck\Service\AssignmentService');
			$assignments->assignUser($cardId, $userId);
		} catch (\Throwable $e) {
			// Already assigned, or the user cannot be assigned here — both are fine.
			$this->logger->debug('todofolder: cannot assign ' . $userId . ' to card ' . $cardId . ': ' . $e->getMessage());
		}
	}

	private function description(Task $task, Workspace $workspace): string {
		$lines = [];
		$lines[] = (string)($task->getSummary() ?? '');
		$lines[] = '';
		$lines[] = $this->l10n->t('Workspace: ') . $workspace->getName() . '（' . $workspace->getFolderPath() . '）';
		$lines[] = $this->l10n->t('Priority: ') . $this->priorityLabel($task->getPriority());

		$links = $this->materialLinks($task, $workspace);
		if ($links !== []) {
			$lines[] = '';
			$lines[] = $this->l10n->t('**Files**');
			foreach ($links as $link) {
				$lines[] = sprintf('- [%s](%s)', $link['name'], $link['url']);
			}
		}

		$lines[] = '';
		$lines[] = '_' . $this->l10n->t('Generated by TodoFolder; re-analysing the task updates this card and its labels.') . '_';
		return trim(implode("\n", $lines));
	}

	/**
	 * The task folder plus its files, as links to Nextcloud's own /f/<fileid> route.
	 * Deck's attachment API only takes uploads, so a link is what keeps the card pointing
	 * at the real files instead of duplicating them.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	private function materialLinks(Task $task, Workspace $workspace): array {
		if (!$this->config->deckLinkMaterials()) {
			return [];
		}
		$folder = $this->workspaces->resolveTaskFolder($workspace, $task->getFolderPath());
		if (!$folder instanceof Folder) {
			return [];
		}

		$links = [[
			'name' => '📁 ' . $folder->getName(),
			'url' => $this->url->getAbsoluteURL('/index.php/f/' . $folder->getId()),
		]];

		$files = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$files[] = $node;
			}
		}
		usort($files, static fn (File $a, File $b): int => strcmp($a->getName(), $b->getName()));
		foreach (array_slice($files, 0, 10) as $file) {
			$links[] = [
				'name' => $file->getName(),
				'url' => $this->url->getAbsoluteURL('/index.php/f/' . $file->getId()),
			];
		}
		return $links;
	}

	/**
	 * Deterministic Deck label colour for a tag name, so the same tag keeps its colour
	 * between runs instead of shuffling every analysis.
	 */
	private function colorForTag(string $name): string {
		return substr(md5($name), 0, 6);
	}

	private function priorityLabel(int $priority): string {
		return match ($priority) {
			3 => $this->l10n->t('Urgent'),
			2 => $this->l10n->t('High'),
			0 => $this->l10n->t('Low'),
			default => $this->l10n->t('Normal'),
		};
	}

	/**
	 * Deck colours are named, not numeric: map our priority to one of them.
	 */
	private function colorFor(int $priority): ?string {
		return match ($priority) {
			3 => 'ff0000',
			2 => 'ff9900',
			0 => 'cccccc',
			default => null,
		};
	}

	/**
	 * Run $fn with $userId as the session user, then restore whatever was there.
	 *
	 * Deck resolves the acting user from the session when its services are built, so a
	 * background job or an occ command — where there is no session at all — must switch the
	 * user here, before Deck's card service is resolved, or every write is refused with
	 * "Permission denied".
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T|null
	 */
	private function asUser(string $userId, callable $fn) {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			$this->logger->warning('todofolder: unknown user ' . $userId . ', skipping Deck');
			return null;
		}
		$previous = $this->userSession->getUser();
		$previousLegacy = \OC_User::getUser();
		$this->userSession->setUser($user);
		// Deck still reads the legacy global in a few permission paths, and its file
		// operations need the mount of the acting user.
		\OC_User::setUserId($userId);
		\OC_Util::setupFS($userId);
		try {
			return $fn();
		} finally {
			\OC_Util::tearDownFS();
			if ($previousLegacy !== false && $previousLegacy !== '') {
				\OC_Util::setupFS($previousLegacy);
			}
			\OC_User::setUserId($previousLegacy === false ? '' : $previousLegacy);
			$this->userSession->setUser($previous);
		}
	}
}
