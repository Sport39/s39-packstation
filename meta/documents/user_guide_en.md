# S39Packstation

Marktplaetze wie Kaufland liefern Packstation-Adressen oft so: Firma = "DHL PACKSTATION 221 Post ID 1030 6634 70", Strasse = Standort der Packstation. DHL/Sendcloud schneiden das ab, das Paket geht zurueck.

Das Plugin prueft jeden neuen Auftrag. Erkennt es eine Packstation/Postfiliale mit eindeutiger Nummer und Postnummer, schreibt es die Lieferadresse um:

- Name 1 (Firma) = Postnummer
- Strasse = PACKSTATION bzw. POSTFILIALE
- Hausnummer = Nummer der Packstation/Filiale
- Adressoption "Postnummer" = Postnummer

Vor- und Nachname, PLZ, Ort und Land bleiben unveraendert. Ist etwas nicht eindeutig, wird nichts geaendert und am Auftrag eine Notiz "manuell pruefen" hinterlassen.

Manuell fuer einen bestehenden Auftrag (nur mit Backend-Login): GET /rest/s39packstation/fix?orderId=123 (mit &dryRun=1 nur anzeigen).
