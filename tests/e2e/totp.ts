import { createHmac } from 'node:crypto';
import type { Page } from '@playwright/test';
import { expect } from './fixtures';

/** RFC 6238 TOTP (SHA-1, 30 s, 6 digits), as an authenticator app computes it. */
export function totp(base32: string, at = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of base32.replace(/=+$/, '')) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(
        bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)),
    );
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return code.toString().padStart(6, '0');
}

const lastWindow = new Map<string, number>();

/**
 * Signs a seeded staff account in through the two-factor challenge. Fortify refuses a code
 * that was already used, so a second sign-in with the same secret waits for the next one.
 */
export async function logInWithTotp(
    page: Page,
    email: string,
    secret: string,
): Promise<void> {
    const windowOf = () => Math.floor(Date.now() / 1000 / 30);
    while (lastWindow.get(secret) === windowOf()) {
        await page.waitForTimeout(1000);
    }
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await page.locator('#otp').click();
    lastWindow.set(secret, windowOf());
    await page.keyboard.type(totp(secret));
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}
