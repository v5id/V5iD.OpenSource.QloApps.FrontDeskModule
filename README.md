# V5iD Front Desk

A QloApps back-office module that adds a front desk workspace — room board,
guest search, check-in/check-out, room swap, and V5iD-powered ID scan
verification — plus a companion **V5iD Scanner Manager** tab for connecting
physical ID scanners.

## Before you start: register on the V5iD portal

> **Properties and devices must be registered with the V5iD portal before
> this module can validate any scans.**
>
> 👉 [https://portal.v5id.net/](https://portal.v5id.net/)

Each property (hotel) is its own V5iD **integration**. For each one, in
the portal:

1. Note the integration's **Integration ID** (a UUID). You'll enter it in
   the module settings.
2. Add this module's **Redirect URL** to the integration. The module
   settings page shows the exact URL for your shop (for example
   `https://your-hotel.example/module/v5idfrontdesk/oauthcallback`). V5iD
   only returns a scanner's sign-in to a URL registered here, and it must
   match exactly.
3. Register the **serial number** of every physical scanner used at that
   property.

Scanners sign in to V5iD with OAuth 2.0 Authorization Code + PKCE. Staff
click **Sign in to V5iD** on a paired scanner in Scanner Manager and type
the integration's primary or secondary **key** into V5iD's own sign-in
page. The key is never entered into, sent through, or stored by QloApps.
V5iD returns a session for that one scanner. The module keeps it
server-side (it never reaches the browser) and renews it automatically as
scans come in. V5iD ends a scanner's session after at most seven days,
after which it needs to be signed in again.

Keeping one integration per property also means that logging into the
V5iD portal for one property only shows that property's verifications.

> **Upgrading from an earlier version?** The integration-key setting is
> gone. Upgrading deletes any stored keys and existing device tokens. Enter
> each property's Integration ID, register the Redirect URL in the portal,
> then sign each scanner in once from Scanner Manager.

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

1. **V5id API — system-wide**: a single **API base URL** for the whole
   installation (defaults to `https://api.v5id.net/api/v1`), not specific to
   any one property. Scanner sign-in uses the same server's origin (its
   `/oauth/` endpoints).
2. **Property**: pick which hotel you're configuring. The integration below
   is per property, so repeat this for every hotel that uses V5iD.
3. **V5id integration for this property**:
   - **Integration ID**: the integration's UUID from the portal. Changing
     it signs out every scanner at that property.
   - **Redirect URL**: read-only. Copy it into the integration's redirect
     URLs in the portal. It has to be HTTPS, so enable SSL for the shop
     first.
   - **Test connection** checks that the V5iD sign-in server answers and
     that it accepts this Integration ID and Redirect URL. It reports which
     of the two is wrong if one is.
4. **Scanner protocols — all properties**: a system-wide allow-list of
   scanner adapters (e.g. Inateck Bluetooth, MagTek HID, Marson Bluetooth,
   Tera Bluetooth). Turning one on here doesn't connect anything by itself.
   It only makes that protocol available for pairing in Scanner Manager,
   which is where a real device serial comes from.

The sign-in callback is a front-office page. If the shop is in maintenance
mode, add the IP of every front desk computer to the maintenance IP list,
or scanners can't finish signing in.

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
   Once paired, that unit is remembered for this property: its serial and
   label are stored, and it gets its own row in Scanner Manager. The browser
   still shows its own device chooser each time you click **Connect** on that
   row, because the browser-side pairing is not retained; pick the same unit
   and the module checks that the serial it reports matches the one on the
   row, refusing to connect if you pick a different one.
3. Click **Sign in to V5iD** on the scanner's row. A V5iD window opens: type
   the property's integration key there. The window closes itself when the
   sign-in succeeds, and the row shows **Signed in to V5iD**. A scanner that
   isn't signed in can still connect, but the board answers its scans with
   a "sign in" message and a shortcut back to Scanner Manager. **Sign out**
   on the same row ends the session at V5iD too, and so does removing the
   scanner.

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
- **ID scan verification**: scan a guest's ID. The module sends it to the
  V5iD API under the scanning device's own V5iD session and shows the
  verification result inline.

## Data retention

The module keeps its own scan and activity logs (`v5idfrontdesk_scan_log`,
`v5idfrontdesk_activity_log`). Uninstalling the module deletes these logs
along with paired scanner devices, and revokes each scanner's V5iD session
first.
