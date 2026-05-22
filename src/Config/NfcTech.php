<?php

namespace NativeBlade\Config;

/**
 * Android NFC technology classes accepted by `<tech-list>` entries in the
 * `TECH_DISCOVERED` filter.
 *
 * Used by `AndroidConfig::nfcAutoLaunch(techs: [...])`. The enum value matches
 * the simple class name; the generator prepends `android.nfc.tech.` when
 * writing `res/xml/nfc_tech_filter.xml`.
 *
 * Source: https://developer.android.com/reference/android/nfc/tech/package-summary
 */
enum NfcTech: string
{
    /** Contactless smartcards (credit cards, transit cards, NFC passports). ISO/IEC 14443-4. */
    case ISO_DEP = 'IsoDep';

    /** Low-level NFC-A (ISO 14443-3A): MIFARE Classic and most Android phone tags. */
    case NFC_A = 'NfcA';

    /** Low-level NFC-B (ISO 14443-3B): some ID cards. */
    case NFC_B = 'NfcB';

    /** Low-level FeliCa (JIS 6319-4): Japanese transit cards, e-money. */
    case NFC_F = 'NfcF';

    /** Low-level NFC-V (ISO 15693): vicinity tags, library books. */
    case NFC_V = 'NfcV';

    /** Tag exposing structured NDEF messages. The vast majority of consumer NFC tags. */
    case NDEF = 'Ndef';

    /** Blank tag that can be formatted into NDEF. */
    case NDEF_FORMATABLE = 'NdefFormatable';

    /** MIFARE Classic 1K / 4K (legacy access control, transit). */
    case MIFARE_CLASSIC = 'MifareClassic';

    /** MIFARE Ultralight / Ultralight C (event tickets, paper-thin tags). */
    case MIFARE_ULTRALIGHT = 'MifareUltralight';

    /** Barcode-style NFC tags exposing a Kovio barcode payload. */
    case NFC_BARCODE = 'NfcBarcode';
}
