<?php

namespace App\Services\OfflineScannerResults;

use App\Models\OfflineScannerSession;
use App\Services\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

class OfflineScannerResultsCryptoService
{
    public function __construct(
        private readonly OfflineScannerResultsValidator $validator,
        private readonly CanonicalJson $json,
    ) {
    }

    public function decryptAndValidate(OfflineScannerSession $session, array $envelope): ResultsPayload
    {
        $validatedEnvelope = $this->validator->validateEnvelope($envelope, $session);
        $payload = $this->decryptPayload($session, $validatedEnvelope);

        return $this->validator->validatePayload($payload, $validatedEnvelope, $session);
    }

    public function decryptPayload(OfflineScannerSession $session, ResultsEnvelope|array $envelope): array
    {
        $validatedEnvelope = is_array($envelope)
            ? $this->validator->validateEnvelope($envelope, $session)
            : $envelope;

        $key = $this->resultEncryptionKey($session);
        $iv = base64_decode($validatedEnvelope->initializationVector, true);
        $ciphertext = base64_decode($validatedEnvelope->encryptedPayload, true);
        $tag = base64_decode($validatedEnvelope->authenticationTag, true);
        $additionalAuthenticatedData = $this->json->encode($validatedEnvelope->bindingData());

        if ($iv === false || $ciphertext === false || $tag === false) {
            $this->fail('Results envelope contains invalid encryption bytes.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $additionalAuthenticatedData,
        );

        if ($plaintext === false) {
            $this->fail('Results file authentication failed or the payload could not be decrypted.');
        }
        try {
            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->fail('Results payload is not valid JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->fail('Results payload must be a JSON object.');
        }

        return $decoded;
    }

    private function resultEncryptionKey(OfflineScannerSession $session): string
    {
        $encryptedSecret = (string) $session->result_file_encryption_secret_encrypted;
        if ($encryptedSecret === '') {
            $this->fail('This offline scanner session does not have a result encryption key.');
        }

        $encodedSecret = Crypt::decryptString($encryptedSecret);
        $key = base64_decode($encodedSecret, true);

        if ($key === false || strlen($key) !== 32) {
            $this->fail('This offline scanner session has an invalid result encryption key.');
        }

        return $key;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['offline_results' => $message]);
    }
}