# Front Office
Booking, reservasi, check-in/out, kamar, lifecycle operasional.

ROUTES: 020_hotel_booking.php, 018_public_reservation_inbox.php
SUPPORT: 035_guest_security_deposits.php, 039_operational_domain_records.php,
         040_rooms_checkout_housekeeping.php, 042_operational_lifecycle_invariants.php

Invariant kunci: FIX28 shift check-in, FIX27 room-scoped draft,
                 checkout lunas bebas shift, overlap stay-window tunggal.
