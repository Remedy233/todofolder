<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Turns a task folder into something a model can read: a file inventory plus the text
 * of the readable files, bounded by configuration.
 *
 * What is deliberately NOT read: anything outside the task folder, and every binary
 * format. A PDF or a .docx contributes its name and size only — that is enough for a
 * title and a due date, and it keeps the prompt small and free of surprises.
 */
class ContentExtractor {
	/** Extensions we read as text, mapped to nothing: the check is the list itself. */
	private const TEXT_EXTENSIONS = [
		'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'yaml', 'yml', 'xml', 'html', 'htm',
		'log', 'ini', 'conf', 'sh', 'bash', 'py', 'js', 'ts', 'php', 'sql', 'css', 'rtf',
	];

	public function __construct(
		private ConfigService $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{
	 *   files: array<int, array{name: string, size: int, mtime: int, text: bool}>,
	 *   text: string,
	 *   hash: string,
	 *   truncated: bool
	 * }
	 */
	public function extract(Folder $folder): array {
		$limit = $this->config->maxFiles();
		$maxBytes = $this->config->maxFileBytes();
		$maxChars = $this->config->maxChars();

		$files = [];
		$chunks = [];
		$truncated = false;
		$budget = $maxChars;
		$seen = 0;

		$nodes = $folder->getDirectoryListing();
		usort($nodes, static fn (Node $a, Node $b): int => strcmp($a->getName(), $b->getName()));

		foreach ($nodes as $node) {
			if ($seen >= $limit) {
				$truncated = true;
				break;
			}
			if (!$node instanceof File) {
				continue;
			}
			$seen++;
			$name = $node->getName();
			$size = (int)$node->getSize();
			$mtime = (int)$node->getMTime();
			$isText = $this->isText($name);
			$files[] = ['name' => $name, 'size' => $size, 'mtime' => $mtime, 'text' => $isText];

			if (!$isText || $budget <= 0) {
				continue;
			}
			try {
				$content = $node->getContent();
			} catch (\Throwable $e) {
				$this->logger->debug('todofolder: cannot read ' . $name . ': ' . $e->getMessage());
				continue;
			}
			if (strlen($content) > $maxBytes) {
				$content = substr($content, 0, $maxBytes);
				$truncated = true;
			}
			$content = (string)mb_convert_encoding($content, 'UTF-8', 'UTF-8');
			$content = mb_substr($content, 0, $budget);
			$budget -= mb_strlen($content);
			$chunks[] = sprintf("### %s\n%s", $name, trim($content));
		}

		return [
			'files' => $files,
			'text' => implode("\n\n", $chunks),
			'hash' => $this->hashOf($folder->getName(), $files),
			'truncated' => $truncated,
		];
	}

	/**
	 * Cheap change signal: names, sizes and mtimes of the folder's files. Reading full
	 * contents on every scan would be wasteful and, on large folders, slow.
	 *
	 * @param array<int, array{name: string, size: int, mtime: int, text: bool}> $files
	 */
	public function hashOf(string $folderName, array $files): string {
		$parts = [$folderName];
		foreach ($files as $file) {
			$parts[] = $file['name'] . ':' . $file['size'] . ':' . $file['mtime'];
		}
		return hash('sha256', implode('|', $parts));
	}

	/**
	 * Subfolder listing only, for the scanner: no contents, no hash.
	 *
	 * @return Folder[]
	 */
	public function taskFolders(Folder $workspaceFolder): array {
		$out = [];
		foreach ($workspaceFolder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			if ($this->isIgnoredFolderName($node->getName())) {
				continue;
			}
			$out[] = $node;
		}
		usort($out, static fn (Folder $a, Folder $b): int => strcmp($a->getName(), $b->getName()));
		return $out;
	}

	/**
	 * Folders that are never tasks: dotfolders, editor scratch dirs, archive templates.
	 */
	public function isIgnoredFolderName(string $name): bool {
		if ($name === '' || $name[0] === '.') {
			return true;
		}
		// Leading underscore is the convention for "_templates", "_done", "_archive".
		return $name[0] === '_';
	}

	private function isText(string $name): bool {
		$dot = strrpos($name, '.');
		if ($dot === false) {
			return false;
		}
		$ext = strtolower(substr($name, $dot + 1));
		return in_array($ext, self::TEXT_EXTENSIONS, true);
	}
}
