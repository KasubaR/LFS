<?php

namespace App\Services\Election;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class ElectionVoteCrypto
{
    /**
     * @param  array{candidate_id?: ?string, abstain?: bool}  $payload
     */
    public function encrypt(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{candidate_id?: ?string, abstain?: bool}
     */
    public function decrypt(string $ciphertext): array
    {
        try {
            $json = Crypt::decryptString($ciphertext);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Vote ciphertext could not be decrypted.');
        }

        if (! is_array($data)) {
            throw new RuntimeException('Invalid vote payload.');
        }

        return $data;
    }

    public function withVoteKey(callable $callback): mixed
    {
        $voteKey = (string) (config('elections.vote_key') ?: '');
        if ($voteKey === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('ELECTION_VOTE_KEY is required in production.');
            }

            return $callback();
        }

        $original = config('app.key');
        config(['app.key' => $this->normalizeKey($voteKey)]);

        try {
            return $callback();
        } finally {
            config(['app.key' => $original]);
        }
    }

    private function normalizeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            return $key;
        }

        return 'base64:'.base64_encode(hash('sha256', $key, true));
    }
}
