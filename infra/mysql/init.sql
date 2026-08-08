-- Dijalankan SEKALI, saat volume MySQL masih kosong. Menjalankannya ulang pada
-- volume yang sudah berisi data tidak terjadi — entrypoint MySQL melewatinya
-- diam-diam. Jadi menambah database baru di sini TIDAK cukup untuk server yang
-- sudah hidup; jalankan CREATE DATABASE-nya sendiri di sana.
--
-- Lima database, satu server. Pemisahannya tetap nyata (tak ada JOIN lintas
-- service, tiap service cuma memegang kredensial ke miliknya lewat DB_DATABASE),
-- tapi tak menuntut lima container untuk satu kafe.

CREATE DATABASE IF NOT EXISTS `fnbsense_iam`          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `fnbsense_catalog`      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `fnbsense_ordering`     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `fnbsense_inventory`    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `fnbsense_notification` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Ditambah 2026-08-08 bersama layar /shift. Baca peringatan di atas: server
-- yang volumenya SUDAH berisi tak akan menjalankan berkas ini lagi, jadi di
-- sana jalankan CREATE DATABASE + GRANT ini sendiri sebelum `migrate`.
CREATE DATABASE IF NOT EXISTS `fnbsense_finance`      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Hak diberikan per database, TIDAK lewat pola `fnbsense_%`. Di MySQL, `_`
-- adalah wildcard satu karakter dalam pola GRANT, jadi pola itu diam-diam juga
-- mencakup database lain yang kebetulan berpola sama — termasuk yang belum ada.
GRANT ALL PRIVILEGES ON `fnbsense_iam`.*          TO 'fnbsense'@'%';
GRANT ALL PRIVILEGES ON `fnbsense_catalog`.*      TO 'fnbsense'@'%';
GRANT ALL PRIVILEGES ON `fnbsense_ordering`.*     TO 'fnbsense'@'%';
GRANT ALL PRIVILEGES ON `fnbsense_inventory`.*    TO 'fnbsense'@'%';
GRANT ALL PRIVILEGES ON `fnbsense_notification`.* TO 'fnbsense'@'%';
GRANT ALL PRIVILEGES ON `fnbsense_finance`.*      TO 'fnbsense'@'%';

FLUSH PRIVILEGES;
