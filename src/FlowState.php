<?php

declare(strict_types=1);

namespace Authentik;

/**
 * Flow state: the PKCE verifier, state and nonce that tie a callback to the
 * sign-in that started it.
 *
 * Stored in the session, because a callback arrives in a separate request and
 * the values must never be readable by browser JavaScript.
 */
final class FlowState
{
    public function __construct(
        public readonly string $codeVerifier,
        public readonly string $state,
        public readonly string $nonce,
        public readonly string $instance,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'code_verifier' => $this->codeVerifier,
            'state' => $this->state,
            'nonce' => $this->nonce,
            'instance' => $this->instance,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            codeVerifier: (string) ($data['code_verifier'] ?? ''),
            state: (string) ($data['state'] ?? ''),
            nonce: (string) ($data['nonce'] ?? ''),
            instance: (string) ($data['instance'] ?? ''),
        );
    }
}
