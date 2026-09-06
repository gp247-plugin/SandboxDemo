> 🌐 **Ngôn ngữ:** 🇻🇳 Tiếng Việt (hiện tại) · [🇬🇧 English](./readme.md)

# SandboxDemo — Chế độ demo admin chỉ-đọc cho GP247 v3

## Giới thiệu
Plugin **SandboxDemo** biến trang quản trị GP247 thành một **bản demo an toàn**: người vào trải nghiệm có thể **xem mọi tính năng** (duyệt, tìm kiếm, mở form, phân trang) nhưng **không thể thay đổi dữ liệu** — mọi thao tác Lưu/Xóa/Sửa đều bị chặn và hiện thông báo "chế độ demo". Tài liệu này dành cho **chủ site** muốn công khai một bản demo admin, và cho **lập trình viên** cần hiểu/tùy chỉnh cơ chế chặn. Đọc xong bạn sẽ bật/tắt được chế độ demo và biết chính xác thao tác nào bị chặn, thao tác nào cho phép.

## 1. Thông tin plugin
- **Tên**: SandboxDemo module
- **Nhà phát triển**: GP247
- **Yêu cầu Core**: `>= 2.1` (GP247 v3 — Laravel + Livewire)
- **Đường dẫn**: `app/GP247/Plugins/SandboxDemo`
- **Công tắc**: biến `SANDBOX_DEMO_ENABLED` trong file `.env`

## 2. Cơ chế hoạt động (dễ hiểu)
GP247 v3 chạy giao diện admin bằng **Livewire** — mọi nút bấm (kể cả Lưu, Xóa) đều gửi về một địa chỉ chung, nên cách chặn cũ "chặn theo kiểu request" không còn tác dụng. Vì vậy SandboxDemo chặn ở **hai lớp**, không phụ thuộc giao diện:

- **Lớp A — chặn ghi cơ sở dữ liệu.** Plugin đứng ngay trước cửa của mọi câu lệnh gửi tới cơ sở dữ liệu. Câu lệnh **đọc** (xem danh sách, tìm kiếm) luôn cho qua; câu lệnh **ghi** (thêm/sửa/xóa dữ liệu) bị chặn. Nhờ đứng ở tầng dữ liệu, nó bắt được mọi đường ghi — dù đến từ Livewire, từ controller cũ, hay từ truy vấn trực tiếp.
- **Lớp B — chặn thao tác file nguy hiểm.** Trình quản lý ảnh/file (File Manager) có những thao tác xóa/đổi tên/di chuyển/cắt ảnh/tạo thư mục chạy bằng đường link thường (GET). Lớp B chặn đúng các thao tác này theo tên, còn xem/duyệt file vẫn cho phép.

Để hệ thống vẫn chạy được khi đang "xem" (đăng nhập, ghi nhớ phiên, bộ đệm), một số bảng **hạ tầng** luôn được phép ghi (xem mục Điều kiện & ràng buộc).

## 3. Cài đặt và bật chế độ demo
1. Đảm bảo mã nguồn nằm đúng đường dẫn `app/GP247/Plugins/SandboxDemo`.
2. Vào trang quản trị **Extensions (Tiện ích mở rộng)**, tìm plugin **SandboxDemo**, nhấn **Install (Cài đặt)**.
3. Sau khi cài, nhấn **Enable (Kích hoạt)**.
4. Mở file `.env` ở thư mục gốc của site, thêm (hoặc sửa) đúng dòng sau rồi lưu lại:

   ```
   SANDBOX_DEMO_ENABLED=1
   ```

5. Xóa bộ nhớ đệm cấu hình để thay đổi có hiệu lực. Mở **Terminal** tại thư mục gốc site, gõ dòng sau rồi nhấn Enter:

   ```
   php artisan optimize:clear
   ```

   Nếu thành công, màn hình hiện vài dòng `... DONE`. Từ lúc này, đăng nhập admin và thử bấm **Lưu** ở bất kỳ màn nào — hệ thống sẽ báo "Chế độ demo: không thể thay đổi dữ liệu hệ thống" và dữ liệu **không đổi**.

**Tắt chế độ demo:** đổi lại thành `SANDBOX_DEMO_ENABLED=0` (hoặc xóa dòng đó) rồi chạy lại `php artisan optimize:clear`. Đặt `=0` sẽ tắt chặn **ngay cả khi** plugin vẫn đang Enable trong admin.

## 4. Tùy chỉnh (dành cho lập trình viên)
Mọi danh sách đều khai trong file `app/GP247/Plugins/SandboxDemo/config.php` — sửa ở đây, **không cần** đụng vào mã lõi:

- `infra_write_allowlist` — các bảng **luôn được ghi** dù đang demo (mặc định: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`). Nếu site của bạn có bảng ghi-nền khác (ví dụ bảng đếm lượt xem, nhật ký) khiến trang bị chặn nhầm khi xem, hãy thêm tên bảng (đã bỏ tiền tố) vào đây.
- `lfm_destructive_routes` — danh sách route File Manager bị chặn khi demo. Thêm/bớt theo nhu cầu.

## 5. Điều kiện & ràng buộc (hiểu trước khi thao tác)
Nắm rõ luật chơi để không bất ngờ khi thấy thông báo chặn:

**Khi nào chế độ demo có hiệu lực?**
- **Phải bật công tắc** `SANDBOX_DEMO_ENABLED=1` trong `.env` — nếu `=0` hoặc không có, plugin không chặn gì (dù đã Enable trong admin).
- **Phải có người đăng nhập** admin / đối tác PMO / vendor — vì thế thao tác **đăng nhập** và các trang trước khi đăng nhập vẫn chạy bình thường (không bị chặn nhầm).

**Thao tác nào bị chặn (khi demo có hiệu lực)?**
- **Mọi thao tác ghi dữ liệu**: thêm, sửa, xóa bản ghi ở bất kỳ màn nào — vì mục tiêu là giữ nguyên dữ liệu demo.
- **Thao tác file nguy hiểm của File Manager**: xóa, đổi tên, di chuyển, resize, cắt ảnh, tạo thư mục, tải ảnh lên — kể cả khi chúng chạy bằng đường link GET.
- **Câu ghi không xác định được bảng** cũng bị chặn (nguyên tắc an toàn: thà chặn nhầm còn hơn cho lọt).

**Thao tác nào luôn được phép?**
- **Mọi thao tác xem**: mở danh sách, tìm kiếm, lọc, phân trang, mở form xem — để trải nghiệm không bị gián đoạn.
- **Ghi vào bảng hạ tầng** (phiên đăng nhập, bộ đệm, hàng đợi) — để không bị đăng xuất giữa chừng và hệ thống vẫn vận hành.

**Ngoài phạm vi (chưa chặn ở bản này):** các tác động **ra ngoài cơ sở dữ liệu** như gửi email thật, gọi API cổng thanh toán, webhook — hiện **chưa** bị chặn. Với server demo công khai, nên cấu hình email/cổng thanh toán ở chế độ thử (test/sandbox của nhà cung cấp) để an toàn.

## 6. Hỗ trợ
- Website: `https://GP247.net`
- Email: `support@gp247.net`

## Hỏi & Đáp (Q&A)
**Câu 1: Tôi đã Enable plugin trong admin mà sao chưa thấy chặn gì?**

→ Vì còn thiếu công tắc `.env`. Thêm `SANDBOX_DEMO_ENABLED=1` vào file `.env`, chạy `php artisan optimize:clear`, rồi thử lại.

**Câu 2: Bật demo lên thì người xem có bị đăng xuất liên tục không?**

→ Không. Các bảng phiên đăng nhập/bộ đệm nằm trong danh sách luôn-được-ghi, nên phiên đăng nhập vẫn giữ bình thường khi đang xem.

**Câu 3: Người xem có phân trang, tìm kiếm được không hay bị chặn hết?**

→ Xem, tìm kiếm, lọc, phân trang đều được phép — chỉ các thao tác **thay đổi dữ liệu** mới bị chặn.

**Câu 4: Người xem xóa/sửa ảnh trong Quản lý file có được không?**

→ Không. Các thao tác xóa/đổi tên/di chuyển/cắt ảnh/tạo thư mục/tải lên đều bị chặn, kể cả khi chúng chạy bằng link GET. Chỉ xem/duyệt file là được.

**Câu 5: Khi bị chặn, người xem thấy gì?**

→ Một thông báo gọn "Chế độ demo: không thể thay đổi dữ liệu hệ thống" (dạng thông báo nổi trên màn admin Livewire), không phải trang lỗi thô.

**Câu 6: Trang bị chặn nhầm ở một chỗ chỉ để xem thì sửa sao?**

→ Rất có thể trang đó ghi vào một bảng nền (đếm lượt xem, nhật ký). Thêm tên bảng đó vào `infra_write_allowlist` trong `config.php` rồi xóa cache.

**Câu 7: Demo có chặn gửi email hay thanh toán thật không?**

→ Bản hiện tại **chưa** chặn các tác động ra ngoài cơ sở dữ liệu (email, cổng thanh toán, webhook). Hãy cấu hình chúng ở chế độ thử cho server demo.

**Câu 8: Làm sao tắt nhanh chế độ demo?**

→ Đổi `SANDBOX_DEMO_ENABLED=0` trong `.env` (hoặc xóa dòng đó), chạy `php artisan optimize:clear`. Tắt được ngay cả khi plugin vẫn Enable.

**Câu 9: Vì sao không dùng lại cách chặn cũ (chặn theo kiểu request)?**

→ Trên GP247 v3, mọi hành động admin đi qua một địa chỉ chung của Livewire, nên chặn theo kiểu request không còn thấy được thao tác ghi. Vì thế bản mới chặn ở tầng cơ sở dữ liệu và tầng thao tác file.

**Câu 10: Plugin có sửa dữ liệu hay cấu trúc gì của tôi không?**

→ Không. Plugin chỉ **chặn** khi demo bật; nó không thêm bảng, không đổi dữ liệu nghiệp vụ của bạn.

---

<sub>📅 **Cập nhật lần cuối:** 2026-09-06 · ✍️ **Tác giả (Author):** GP247</sub>
