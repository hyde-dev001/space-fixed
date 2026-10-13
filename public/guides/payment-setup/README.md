# Payment setup demo videos

Two silent WebM videos with Tagalog instructions, demo forms and a moving cursor.
The settings page embeds them with native controls and links to text transcripts.
All account information and keys shown are fictional. These are simplified demo
screens, not recordings of the providers' websites. Provider screens and requested
documents may change or vary by business type.

Regenerate from the repository root with:

```sh
python scripts/generate-payment-tutorials.py
```

The generator uses Python Playwright with Chromium and its bundled FFmpeg, already
available in the browser-testing environment. Install those tools separately if
running elsewhere; they are not application dependencies. It makes no network
requests and checks that each demo form fits on screen.

Official references checked on 2026-10-13:

- [PayMongo signup and verification](https://docs.paymongo.com/docs/get-started-create-your-account)
- [PayMongo API keys](https://docs.paymongo.com/docs/account-settings-api-keys)
- [Xendit signup and business verification](https://docs.xendit.co/docs/create-account)
- [Xendit payout API key permissions](https://docs.xendit.co/docs/payouts-creating-an-api-key)
- [Xendit PAYOUT webhook configuration](https://docs.xendit.co/apidocs/payout-v3-webhook)
- [Xendit webhook verification token](https://help.xendit.co/hc/en-us/articles/360038048871-How-Do-I-Set-and-Update-The-Webhook-URL)

Deploy the entire `public/guides/payment-setup/` directory with the settings change.
