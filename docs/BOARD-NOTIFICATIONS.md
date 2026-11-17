# Unread requests

The company navigation labels the existing kanban board “Aanvragen”. Its red badge counts unread incoming public leads and unlinked confirmed public bookings when the basic board module is enabled. Manual entries do not create an incoming notification. The agency inbox has its own independent badge.

Read receipts live in ab_board_reads, scoped to user, tenant and source record. The schema is created automatically under a migration lock; no manual config changes are required. Opening the board fetches current data and acknowledges only records in that displayed snapshot. It does not change lead stages. A later arrival cannot be swallowed by a previous acknowledgement; superadmin views do not clear a customer's receipts.

The authenticated board/notifications endpoint supplies the count, also included in workspace. The CSRF-protected board/seen POST accepts at most 2000 lead and 2000 booking identifiers and only writes receipts for the authorized tenant. The UI checks every 60 seconds while visible and on window focus. Stale polling responses cannot override a newer read acknowledgement. Large workspaces retain the existing 2000-record display limits; unseen records outside those snapshots remain unread.
