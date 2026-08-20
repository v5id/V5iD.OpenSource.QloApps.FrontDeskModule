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

Each property (hotel) needs its own V5iD integration **secret** issued from
the portal, and each physical scanner needs its own **serial number**
registered there too — the V5iD API issues a token per device (secret +
serial together) and rejects any request that doesn't carry a registered
serial. The module exchanges these server-side for a short-lived token used
to validate ID scans, scoped to that specific device; it never sends your
secret to the browser, and a token issued for one device can't be reused for
another. Register your property (for the secret) and each scanner unit (for
its serial) on the portal *first*, then come back and enter the secret in the
module settings and pair each scanner in Scanner Manager (see below). Without
a valid secret and a registered device serial, scan verification will fail
even if a scanner is physically connected.

Using a separate V5iD integration ID per property also means that logging
into the V5iD portal under one property's secret only shows that property's
verifications — keep that in mind if you manage multiple hotels.

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

1. **V5id API — system-wide** — a single **API base URL** for the whole
   installation (defaults to `https://api.v5id.net/api/v1`), not specific to
   any one property.
2. **Property** — pick which hotel you're configuring. The secret below is
   per-property, so repeat this step for every hotel that uses V5iD.
3. **V5id secret for this property** — enter the secret you generated for
   this property on [portal.v5id.net](https://portal.v5id.net/). To **Test
   connection**, also enter the serial number of any device already
   registered on the portal for this property (e.g. one already paired in
   Scanner Manager) — the API requires a serial on every request, so this
   field exists purely to give the test something to authenticate as. It's
   never saved.
4. **Scanner protocols — all properties** — a system-wide allow-list of
   scanner adapters (e.g. Inateck Bluetooth, MagTek HID, Marson Bluetooth).
   Turning one on here doesn't connect anything by itself; it just makes
   that protocol available for pairing in Scanner Manager, which is where a
   real device serial comes from — see the pairing section below for why
   that matters.

## Pairing a physical scanner

Pairing is done per property from the **V5iD Scanner Manager** tab (open it
from the **Front Desk** board), and is where this module gets the serial
number it needs to validate that device's scans against the V5iD API — make
sure the same serial is already registered for this property on
[portal.v5id.net](https://portal.v5id.net/) before pairing it here.

1. Open the Front Desk board for the property and click **Open Scanner
   Manager**. Keep that tab open for the shift — it holds the live
   connection to the scanner(s) and forwards every scan (and its serial) to
   your Front Desk tabs.
2. Pick the scanner's protocol (e.g. Bluetooth GATT) and connect it — the
   adapter reads the physical unit's serial number directly from the device.
   Once paired, that unit is remembered for this property and reconnects
   automatically next time.

A scanner paired at one property is never visible or usable at another.

> **Plain USB/Bluetooth-HID barcode or MRZ scanners** that just type
> keystrokes have no pairing step and no serial the browser can read, so
> their scans currently have no device identity to authenticate with — the
> board reports a clean "no known device" error for them rather than
> validating against the API. Use a scanner from an enabled protocol above
> (paired in Scanner Manager) if you need working ID scan verification.

## Using the Front Desk board

- **Room board** — see room/date status at a glance (alloted, checked-in,
  checked-out).
- **Guest search** — locate a booking by name, room, or reservation.
- **Check-in / check-out** — update booking/room status.
- **Room swap** — move a guest from one room to another.
- **ID scan verification** — scan a guest's ID; the module sends it to the
  V5iD API (using the property's secret) and shows the verification result
  inline.

## Data retention

The module keeps its own scan and activity logs (`v5idfrontdesk_scan_log`,
`v5idfrontdesk_activity_log`). Uninstalling the module deletes these logs
along with paired scanner devices and stored secrets.
