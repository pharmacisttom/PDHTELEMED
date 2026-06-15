# Thailand Post Integration Guide

ระบบนี้เพิ่มการเชื่อมต่อ Thailand Post Track & Trace ให้หน้า `tracking.php` เพื่อบันทึกเลขพัสดุ ซิงก์สถานะล่าสุด และเก็บประวัติผลลัพธ์จาก API ไว้ในฐานข้อมูลของ PDH Telemed

## ไฟล์ที่เกี่ยวข้อง

- `thailand_post_config.php` - client สำหรับ Thailand Post API
- `api_thailand_post.php` - endpoint ภายในระบบสำหรับ validate, sync, get status และ webhook
- `thailand_post_modal.php` - modal สำหรับกรอกเลขพัสดุและซิงก์สถานะ
- `database_setup_thailand_post.sql` - SQL สำหรับเพิ่ม column, index, log table และ view
- `pdhtawan_schema_full.sql` - schema เต็มของฐาน `pdhtawan` รวมส่วน Thailand Post
- `logs/` - เก็บ webhook log รายวัน

## Endpoint ของ Thailand Post

ระบบใช้ flow มาตรฐานของ Track & Trace API:

1. ขอ token
   `POST https://trackapi.thailandpost.co.th/post/api/v1/authenticate/token`
   Header: `Authorization: Token <API_KEY>`

2. ดึงสถานะพัสดุ
   `POST https://trackapi.thailandpost.co.th/post/api/v1/track`
   Header: `Authorization: Token <TOKEN_FROM_STEP_1>`

Payload สำหรับ track:

```json
{
  "status": "all",
  "language": "TH",
  "barcode": ["EV123456789TH"]
}
```

## การติดตั้งฐานข้อมูล

ถ้ามีฐานข้อมูลเดิมอยู่แล้ว ให้รัน migration นี้บน database `pdhtawan`:

```sql
source database_setup_thailand_post.sql;
```

ถ้าต้องการสร้างฐานใหม่ทั้งก้อน ให้ใช้:

```sql
source pdhtawan_schema_full.sql;
```

คำเตือน: `pdhtawan_schema_full.sql` มี `DROP TABLE` และเหมาะสำหรับฐานใหม่หรือเครื่องทดสอบเท่านั้น

สิ่งที่เพิ่ม:

- `telemed_tracking.tracking_status`
- `telemed_tracking.last_sync`
- `telemed_tracking.sync_data`
- `telemed_tracking.webhook_data`
- `thailand_post_sync_logs`
- `vw_thailand_post_summary`

## การใช้งานหน้า Tracking

ในหน้า `tracking.php` แต่ละแถวจะมีปุ่ม `Thailand Post`

1. กดปุ่ม `Thailand Post`
2. กรอกเลขพัสดุ เช่น `EV123456789TH`
3. กด `ตรวจสอบ` เพื่อดึงสถานะ
4. กด `บันทึกและซิงก์` เพื่อบันทึกลง `telemed_tracking`

ระบบจะอัปเดตสถานะติดตามในระบบเป็น:

- `รอจัดส่ง` ถ้ายังไม่พบสถานะจากไปรษณีย์
- `ส่งแล้ว` ถ้าพบประวัติการเดินทาง
- `ได้รับแล้ว` ถ้าสถานะสื่อว่าจัดส่งสำเร็จ
- `ติดต่อไม่ได้` ถ้าสถานะสื่อว่าจัดส่งไม่สำเร็จหรือตีกลับ

## API ภายในระบบ

Validate:

```http
POST api_thailand_post.php?action=validate_tracking_no
tracking_no=EV123456789TH
```

Get status:

```http
GET api_thailand_post.php?action=get_status&tracking_no=EV123456789TH
```

Sync:

```http
POST api_thailand_post.php?action=sync_tracking_status
hn=123456
regdate=2026-05-29
tracking_no=EV123456789TH
```

Webhook:

```http
POST api_thailand_post.php?action=webhook_receiver
```

## การตั้งค่า Token

ค่าเริ่มต้นอยู่ใน `thailand_post_config.php` แล้ว แต่ production แนะนำให้ตั้งผ่าน environment variable:

```bash
THAILAND_POST_API_KEY=your_api_key
```

## ตรวจสอบหลังติดตั้ง

```bash
php -l thailand_post_config.php
php -l api_thailand_post.php
php -l thailand_post_modal.php
php -l tracking.php
```

จากนั้นเปิดหน้า `tracking.php` และทดสอบกับเลขพัสดุจริงที่มีสถานะในระบบ Thailand Post
