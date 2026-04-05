import crypto from 'node:crypto';

function normalizeBase32(value) {
    return String(value ?? '')
        .toUpperCase()
        .replace(/[^A-Z2-7]/g, '');
}

function base32ToBuffer(value) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';

    for (const char of normalizeBase32(value)) {
        const index = alphabet.indexOf(char);

        if (index === -1) {
            continue;
        }

        bits += index.toString(2).padStart(5, '0');
    }

    const bytes = [];

    for (let offset = 0; offset + 8 <= bits.length; offset += 8) {
        bytes.push(Number.parseInt(bits.slice(offset, offset + 8), 2));
    }

    return Buffer.from(bytes);
}

export function totpCode(secret, timestamp = Date.now()) {
    const counter = Math.floor(timestamp / 1000 / 30);
    const counterBuffer = Buffer.alloc(8);

    counterBuffer.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
    counterBuffer.writeUInt32BE(counter % 0x100000000, 4);

    const digest = crypto.createHmac('sha1', base32ToBuffer(secret)).update(counterBuffer).digest();
    const offset = digest[digest.length - 1] & 0x0f;
    const code = (
        ((digest[offset] & 0x7f) << 24)
        | ((digest[offset + 1] & 0xff) << 16)
        | ((digest[offset + 2] & 0xff) << 8)
        | (digest[offset + 3] & 0xff)
    ) % 1_000_000;

    return String(code).padStart(6, '0');
}
