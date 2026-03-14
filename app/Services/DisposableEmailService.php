<?php

namespace App\Services;

class DisposableEmailService
{
    /**
     * Known disposable/temporary email domains.
     * Add more as needed.
     */
    private static array $blockedDomains = [
        // Mailinator family
        'mailinator.com',
        'trashmail.com',
        'guerrillamail.com',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamail.biz',
        'guerrillamail.de',
        'guerrillamail.info',
        'grr.la',
        'sharklasers.com',
        'guerrillamailblock.com',
        'spam4.me',
        // Yopmail
        'yopmail.com',
        'yopmail.fr',
        'cool.fr.nf',
        'jetable.fr.nf',
        'nospam.ze.tc',
        'nomail.xl.cx',
        'mega.zik.dj',
        'speed.1s.fr',
        'courriel.fr.nf',
        'moncourrier.fr.nf',
        'monemail.fr.nf',
        'monmail.fr.nf',
        // Temp-mail / throwaway
        'tempmail.com',
        'temp-mail.org',
        'tempmail.net',
        'tmpmail.net',
        'tmpmail.org',
        'throwam.com',
        'throwam.net',
        'throwaway.email',
        'dispostable.com',
        'fakeinbox.com',
        'filzmail.com',
        'maildrop.cc',
        'discard.email',
        'spamgourmet.com',
        'spamgourmet.net',
        'spamgourmet.org',
        // 10-minute/one-time mail
        '10minutemail.com',
        '10minutemail.net',
        '10minutemail.org',
        '10minutemail.de',
        '10minutemail.co.uk',
        '10minemail.com',
        '20minutemail.com',
        '1minutemail.com',
        'tempr.email',
        'discard.email',
        // Spam/trash domains
        'spam.la',
        'antispam24.de',
        'trashmail.at',
        'trashmail.io',
        'trashmail.me',
        'trashmail.net',
        'trashmail.xyz',
        'trash-mail.at',
        'spamfree24.org',
        'spamfree24.de',
        'spamfree24.net',
        'spamfree24.info',
        'spamfree24.eu',
        // Fake/example domains
        'example.com',
        'example.net',
        'example.org',
        'test.com',
        'fake.com',
        'noemail.com',
        'noemail.net',
        'noemail.org',
        'no-email.com',
        // Other known disposable
        'mailnull.com',
        'mailnull.net',
        'mailnesia.com',
        'mintemail.com',
        'getairmail.com',
        'mailseal.de',
        'byom.de',
        'sogetthis.com',
        'spikio.com',
        'incognitomail.com',
        'incognitomail.net',
        'incognitomail.org',
        'tempemail.net',
        'discard.email',
        'throwam.com',
        'yomail.info',
        'binkmail.com',
        'bobmail.info',
        'chammy.info',
        'drdrb.com',
        'letthemeatspam.com',
        'lol.ovpn.to',
        'maileater.com',
        'malahov.de',
        'nospamfor.us',
        'privacy.net',
        'quickinbox.com',
        'rcpt.at',
        'spamfree.eu',
        'spamgob.com',
        'spaml.de',
        'spoofmail.de',
        'trbvm.com',
        'trya.sl',
    ];

    /**
     * Check if an email's domain is in the blocked list.
     */
    public function isDisposable(string $email): bool
    {
        $parts = explode('@', strtolower(trim($email)));

        if (count($parts) !== 2) {
            return false;
        }

        $domain = $parts[1];

        return in_array($domain, self::$blockedDomains, true);
    }
}
