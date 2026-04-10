# Supported IMAPv4rev2 Extensions

| Extension | RFC | Support |
|-----------|-----|---------|
| IMAP4rev1 | 3501 | Core |
| IMAP4rev2 | 9051 | Core |
| CONDSTORE | 7162 | `Config::enableCondstore` |
| QRESYNC | 7162 | `Config::enableQresync` |
| OBJECTID | 8474 | Auto (emailId/threadId) |
| MOVE | 6851 | Auto (fallback to COPY+DELETE) |
| STATUS=SIZE | 8438 | Auto |
| SAVEDATE | 8514 | Auto |
| UTF8=ACCEPT | 6855 | `Config::utf8Accept` |
| LIST-STATUS | 5819 | Auto |
| LITERAL- | 7888 | Auto |
| SPECIAL-USE | 6154 | Auto |
| SORT | 5256 | Via transceiver |
| THREAD | 5256 | Via transceiver |
| ID | 2971 | `Config::clientId` / `$mailbox->id()` |
| IDLE | 2177 | `$mailbox->idle()` |
| NAMESPACE | 2342 | `$mailbox->namespace()` |
| ENABLE | 5161 | Auto |
| UNSELECT | 3691 | Auto |
| SASL-IR | 4959 | Auto |

Capabilities marked **Auto** are negotiated transparently when the server advertises them — you do not need to opt in. Extensions marked **Via transceiver** are available through the raw `Transceiver` layer; see [Raw Connection Access](advanced/raw-connection.md).
