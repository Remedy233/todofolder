<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use OCP\Http\Client\IClientService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The only place that talks to a model.
 *
 * Two modes, chosen by configuration rather than by a flag anyone has to remember:
 *
 * - base_url set: OpenAI-compatible POST {base_url}/chat/completions with JSON mode.
 * - base_url empty: MockAnalyzer answers deterministically, so the pipeline
 *   (scan -> extract -> analyse -> persist -> tags -> Deck) can be proven before an
 *   API key exists. Swapping in the real endpoint changes nothing downstream.
 *
 * The client never sees a file path outside the workspace folder: callers hand it a
 * ready prompt.
 */
class AiClient {
	public function __construct(
		private IClientService $clientService,
		private ConfigService $config,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
	}

	/**
	 * Round trip used by the settings page: a prompt small enough to be free, an
	 * answer that proves JSON mode works.
	 *
	 * @return array{model: string, mock: bool, latencyMs: int, sample: array}
	 */
	public function ping(): array {
		$started = microtime(true);
		$answer = $this->chatJson(
			'You answer with a single JSON object and nothing else.',
			'Return {"ok":true,"echo":"todofolder"} and nothing else.',
		);
		return [
			'model' => $this->config->isMock() ? 'mock' : $this->config->model(),
			'mock' => $this->config->isMock(),
			'latencyMs' => (int)round(((float)microtime(true) - (float)$started) * 1000.0),
			'sample' => $answer,
		];
	}

	/**
	 * One JSON-mode completion. Returns the decoded object, or a mock answer when no
	 * endpoint is configured.
	 *
	 * @param array<int, array{role: string, content: string}> $extraMessages
	 * @return array<string, mixed>
	 * @throws \RuntimeException on transport or protocol failure
	 */
	public function chatJson(string $system, string $user, array $extraMessages = []): array {
		if ($this->config->isMock()) {
			return $this->mockAnswer($user);
		}

		$url = $this->config->baseUrl() . '/chat/completions';
		$payload = [
			'model' => $this->config->model(),
			'messages' => [
				['role' => 'system', 'content' => $system],
				...$extraMessages,
				['role' => 'user', 'content' => $user],
			],
			'temperature' => 0.2,
			'response_format' => ['type' => 'json_object'],
		];

		$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
		$key = $this->config->apiKey();
		if ($key !== '') {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$client = $this->clientService->newClient();
		$lastError = null;
		for ($attempt = 1; $attempt <= 2; $attempt++) {
			try {
				$response = $client->post($url, [
					'headers' => $headers,
					'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
					'timeout' => $this->config->timeout(),
					'connect_timeout' => 10,
					// The endpoint is often on the LAN (vLLM, OneAPI, Ollama); without this
					// Nextcloud refuses to talk to private addresses.
					'nextcloud' => ['allow_local_address' => true],
				]);
				$body = (string)$response->getBody();
				return $this->decodeContent($body);
			} catch (\Throwable $e) {
				$lastError = $e;
				$this->logger->warning('todofolder: AI call failed (attempt ' . $attempt . '): ' . $e->getMessage());
				if ($attempt === 1) {
					usleep(400000);
				}
			}
		}
		throw new \RuntimeException($this->l10n->t('Model call failed: ') . ($lastError instanceof \Throwable ? $lastError->getMessage() : $this->l10n->t('Unknown error')));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeContent(string $raw): array {
		try {
			$envelope = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \RuntimeException($this->l10n->t('The model did not return JSON: ') . substr($raw, 0, 200), 0, $e);
		}
		if (!is_array($envelope)) {
			throw new \RuntimeException($this->l10n->t('The model returned an unexpected structure'));
		}
		$content = $envelope['choices'][0]['message']['content'] ?? null;
		if (!is_string($content)) {
			// Some gateways answer with the object directly.
			if (isset($envelope['title']) || isset($envelope['summary'])) {
				return $envelope;
			}
			throw new \RuntimeException($this->l10n->t('The model response has no choices[0].message.content'));
		}
		$content = trim($content);
		// Tolerate a fenced block; models add one even in JSON mode.
		if (str_starts_with($content, '```')) {
			$content = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $content) ?? $content;
		}
		$decoded = json_decode($content, true, 64);
		if (!is_array($decoded)) {
			throw new \RuntimeException($this->l10n->t('The model did not return a JSON object: ') . substr($content, 0, 200));
		}
		return $decoded;
	}

	/**
	 * Deterministic stand-in. It reads the prompt, so the values still look like they
	 * came from the folder rather than being hard-coded, and tests can assert on them.
	 *
	 * @return array<string, mixed>
	 */
	private function mockAnswer(string $prompt): array {
		if (str_contains($prompt, 'Return {"ok":true')) {
			return ['ok' => true, 'echo' => 'todofolder'];
		}
		$folder = $this->l10n->t('Untitled task');
		if (preg_match('/^文件夹名[:：]\s*(.+)$/mu', $prompt, $m) === 1) {
			$folder = trim($m[1]);
		}
		$fileCount = (int)preg_match_all('/^- /mu', $prompt);
		$days = 7 + ($fileCount * 2);
		return [
			'title' => mb_substr($folder, 0, 60),
			'summary' => $this->l10n->t('(mock analysis) folder “%s” holds %d files; placeholder conclusions generated by rule.', [$folder, $fileCount]),
			'due_date' => date('Y-m-d', time() + $days * 86400),
			'priority' => $fileCount > 5 ? 'high' : 'normal',
			'tags' => array_values(array_unique(array_filter([$folder, $fileCount > 5 ? $this->l10n->t('many files') : $this->l10n->t('normal')]))),
			'reason' => $this->l10n->t('mock: deterministic output without a real model, used to exercise scan, analysis, storage, tags and Deck end to end.'),
		];
	}
}
