# V5iD Front Desk

A QloApps back-office module that adds a front desk workspace — room board,
guest search, check-in/check-out, room swap, and V5iD-powered ID scan
verification — plus a companion **V5iD Scanner Manager** tab for connecting
physical ID scanners.

## Before you start: register on the V5iD portal

> **Devices and properties must be registered with the V5iD portal before
> this module can validate any scans.**
>
> 👉 [https://portal.v5id.net/](https://portal.v5id.net/)

Each property (hotel) needs its own V5iD integration credential — a
**serial** and **secret** — issued from the portal. The module exchanges
these server-side for a short-lived API token used to validate ID scans; it
never sends your secret to the browser. Register your property and generate
its credential on the portal *first*, then come back and enter it in the
module settings below. Without a valid credential, scan verification will
fail even if a scanner is physically connected.

Using a separate V5iD integration ID per property also means that logging
into the V5iD portal under one property's credential only shows that
property's verifications — keep that in mind if you manage multiple hotels.

## Installation

1. Get the module as a zip file — either zip up this repo yourself, or
   download a zip from the GitHub release. The zip must contain a single
   top-level `v5idfrontdesk` folder (with `v5idfrontdesk.php` etc. inside
   it).
2. In the back office, go to **Modules > Module Manager** and click **Add a
   new module**.
3. Select the zip file and click **Upload and install this module**.
4. A new **Front Desk** tab appears in the main admin menu.

## Configuration

Go to **Modules > Module Manager > V5iD Front Desk > Configure**.

1. **Property** — pick which hotel you're configuring. Credentials are
   per-property, so repeat this configuration for every hotel that uses
   V5iD.
2. **V5id API credentials for this property**
   - **API base URL** — defaults to `https://api.v5id.net/api/v1`.
   - **V5id serial number** and **V5id secret** — the credential you
     generated for this property on [portal.v5id.net](https://portal.v5id.net/).
   - Click **Test connection** to confirm the credential is valid before
     relying on it.
3. **Scanner protocols — all properties** — a system-wide allow-list of
   scanner adapters (e.g. Inateck Bluetooth, MagTek HID). Turning one on
   here doesn't connect anything by itself; it just makes that protocol
   available for pairing in Scanner Manager. Plain keyboard-wedge
   barcode/MRZ scanners need nothing enabled here — they work
   automatically.

## Pairing a physical scanner

Physical scanner pairing is separate from the portal credential above, and
is done per property from the **V5iD Scanner Manager** tab (open it from the
**Front Desk** board):

1. Open the Front Desk board for the property and click **Open Scanner
   Manager**. Keep that tab open for the shift — it holds the live
   connection to the scanner(s) and forwards every scan to your Front Desk
   tabs.
2. If the scanner uses one of the enabled protocols (e.g. Bluetooth GATT),
   pair it from the Scanner Manager page. Once paired, that unit is
   remembered for this property and reconnects automatically next time.
3. Plain USB/Bluetooth-HID barcode or MRZ scanners that just type keystrokes
   need no pairing step — the Front Desk board listens for them directly.

A scanner paired at one property is never visible or usable at another.

## Using the Front Desk board

- **Room board** — see room/date status at a glance (alloted, checked-in,
  checked-out).
- **Guest search** — locate a booking by name, room, or reservation.
- **Check-in / check-out** — update booking/room status.
- **Room swap** — move a guest from one room to another.
- **ID scan verification** — scan a guest's ID; the module sends it to the
  V5iD API (using the property's credential) and shows the verification
  result inline.

## Data retention

The module keeps its own scan and activity logs (`v5idfrontdesk_scan_log`,
`v5idfrontdesk_activity_log`). Uninstalling the module deletes these logs
along with paired scanner devices and stored credentials.
