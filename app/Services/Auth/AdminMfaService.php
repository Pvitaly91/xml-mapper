<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminMfaService
{
    public function __construct(
        private readonly AdminAuthPolicyService $policyService,
    ) {}

    /**
     * @return array{secret:string,provisioning_uri:string,qr_svg:?string}
     */
    public function beginEnrollment(User $user): array
    {
        $secret = $user->mfa_pending_secret ?: $this->generateSecret();

        $user->forceFill([
            'mfa_pending_secret' => $secret,
        ])->save();

        $uri = $this->provisioningUri($user, $secret);

        return [
            'secret' => $secret,
            'provisioning_uri' => $uri,
            'qr_svg' => $this->qrSvg($uri),
        ];
    }

    /**
     * @return array{recovery_codes:list<string>}
     */
    public function confirmEnrollment(User $user, string $code): array
    {
        $secret = (string) $user->mfa_pending_secret;

        if ($secret === '' || ! $this->verifyTotp($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => 'Invalid authenticator code.',
            ]);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_pending_secret' => null,
            'mfa_recovery_codes' => array_map(static fn (string $value) => [
                'hash' => Hash::make($value),
                'used_at' => null,
            ], $recoveryCodes),
            'mfa_enabled_at' => now(),
            'mfa_last_verified_at' => now(),
        ])->save();

        return ['recovery_codes' => $recoveryCodes];
    }

    /**
     * @return array{method:string}
     */
    public function challenge(User $user, string $code): array
    {
        $normalized = $this->normalizeCode($code);

        if ($user->hasMfaEnabled() && $this->verifyTotp((string) $user->mfa_secret, $normalized)) {
            $user->forceFill(['mfa_last_verified_at' => now()])->save();

            return ['method' => 'totp'];
        }

        $recoveryCodes = (array) ($user->mfa_recovery_codes ?? []);

        foreach ($recoveryCodes as $index => $item) {
            if (($item['used_at'] ?? null) !== null) {
                continue;
            }

            if (! Hash::check($normalized, (string) ($item['hash'] ?? ''))) {
                continue;
            }

            $recoveryCodes[$index]['used_at'] = now()->toIso8601String();
            $user->forceFill([
                'mfa_recovery_codes' => $recoveryCodes,
                'mfa_last_verified_at' => now(),
            ])->save();

            return ['method' => 'recovery'];
        }

        throw ValidationException::withMessages([
            'code' => 'Invalid MFA or recovery code.',
        ]);
    }

    public function reset(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_pending_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_enabled_at' => null,
            'mfa_last_verified_at' => null,
        ])->save();
    }

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = rawurlencode($this->policyService->mfaIssuer());
        $label = rawurlencode($user->email);

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $issuer,
            $label,
            $secret,
            $issuer
        );
    }

    public function currentCode(string $secret, ?int $timestamp = null): string
    {
        return $this->totpCode($secret, $timestamp ?? time());
    }

    public function verifyTotp(string $secret, string $code): bool
    {
        $normalized = $this->normalizeCode($code);

        if (! preg_match('/^\d{6}$/', $normalized)) {
            return false;
        }

        $window = $this->policyService->totpWindow();

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->totpCode($secret, time() + ($offset * 30)), $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($index = 0; $index < $this->policyService->recoveryCodeCount(); $index++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        }

        return $codes;
    }

    private function qrSvg(string $uri): ?string
    {
        if (! class_exists(\BaconQrCode\Writer::class)) {
            return null;
        }

        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(220),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        $writer = new \BaconQrCode\Writer($renderer);

        return $writer->writeString($uri);
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?: '');
    }

    private function totpCode(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, 30);
        $binarySecret = $this->base32Decode($secret);
        $binaryCounter = pack('N2', ($counter & 0xFFFFFFFF00000000) >> 32, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binaryCounter, $binarySecret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';

        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }

            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $value): string
    {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $bits = '';

        foreach (str_split($this->normalizeCode($value)) as $char) {
            if (! array_key_exists($char, $alphabet)) {
                continue;
            }

            $bits .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                continue;
            }

            $binary .= chr(bindec($chunk));
        }

        return $binary;
    }
}
