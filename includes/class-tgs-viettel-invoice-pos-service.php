<?php
// [2026-11-25 Võ Hoàng] Hàm dịch vụ công khai cho POS — thay việc giả lập AJAX ($_POST, nonce, wp_die) từ REST tgs_pos (đóng góp)
/**
 * API dịch vụ hoá đơn Viettel cho máy bán (POS). Mỗi hàm chạy ĐÚNG lõi mà handler AJAX tương ứng đang chạy
 * (handler AJAX giờ chỉ còn: chọn blog theo blog_id + kiểm nonce + đọc $_POST → gọi lõi → wp_send_json), nên luật nghiệp vụ
 * nằm một chỗ, mọi nơi gọi đều qua: quyền dùng máy bán, mật khẩu xác nhận, khoá chống phát hành trùng (GET_LOCK theo
 * site + phiếu), chặn khi hoá đơn đã gửi / đang xử lý, nhật ký gửi CQT...
 *
 * Gọi ở blog hiện tại, với người dùng hiện tại (chưa đăng nhập → lõi trả lỗi quyền như AJAX). Không in, không dừng yêu cầu.
 * Kết quả: thành công → mảng `data` y như AJAX trả trong { success: true, data }; thất bại → WP_Error
 *   code 'tgs_viettel_invoice_failed', message = data.message, data = [ 'status' => HTTP như AJAX (AJAX không đặt mã → 422),
 *   'http_status' => mã AJAX đặt (null = AJAX trả 200), 'details' => nguyên `data` lỗi của AJAX (step, need_confirm,
 *   under24_skus, requires_retry, allow_manual...) ].
 *
 *   list_statuses(array $filters)                         ← tgs_viettel_pos_list_statuses
 *   items_for_review(int $sale_ledger_id)                 ← tgs_viettel_pos_get_items_for_review
 *   update_danger_flags(int $sale_ledger_id, array $flags) ← tgs_viettel_pos_update_danger_flags
 *   issue(int $sale_ledger_id, array $options)            ← tgs_viettel_send_from_sale        (phát hành + gửi CQT)
 *   retry(int $sale_ledger_id, array $options)            ← tgs_viettel_pos_retry_invoice     (gửi lại)
 *   recover_invoice_no(int $sale_ledger_id, array $opts)  ← tgs_viettel_pos_recover_invoice_no (tra số / đền bù)
 *   import_replacement(int, string $no, string $password) ← tgs_viettel_pos_import_replacement
 *   update_invoice_date(int, string $date, string $pass)  ← tgs_viettel_pos_update_invoice_date
 *   send_email(int $sale_ledger_id, string $to_email)     ← tgs_viettel_pos_send_invoice_email
 *   send_email_portal(int $sale_ledger_id, $to_emails)    ← tgs_viettel_pos_send_invoice_email_portal
 *   invoice_pdf(int $sale_ledger_id)                      ← tgs_viettel_pos_preview_invoice_pdf
 *   adjustment_pdf(int $return_ledger_id)                 ← tgs_viettel_pos_preview_adjustment_pdf
 *   sale_debug_log(int $sale_ledger_id, int $limit = 30)  ← tgs_viettel_get_sale_debug_log
 *   preview_return_adjustment(int $queue_id)              ← tgs_viettel_preview_return_adjustment (chỉ xem, không phát hành)
 *   confirm_return_adjustment(int $queue_id)              ← tgs_viettel_confirm_return_adjustment (phát hành + gửi CQT)
 *
 * Bên gọi ở plugin khác: kiểm class_exists('TGS_Viettel_Invoice_Pos_Service') + method_exists trước khi gọi.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_Viettel_Invoice_Pos_Service
{
    /** Phiên bản API dịch vụ (tăng khi thêm / đổi hàm). */
    const VERSION = 1;

    const ERROR_CODE = 'tgs_viettel_invoice_failed';

    // ── Kết quả chung của lõi (dùng nội bộ: lõi ↔ handler AJAX ↔ hàm dịch vụ) ─────────────────────────────────────────

    /**
     * Lõi trả thành công (thay wp_send_json_success).
     *
     * @internal
     * @return array{success: bool, data: mixed, status: int|null}
     */
    public static function ok($data = null, $status = null)
    {
        return ['success' => true, 'data' => $data, 'status' => $status];
    }

    /**
     * Lõi trả thất bại (thay wp_send_json_error).
     *
     * @internal
     * @return array{success: bool, data: mixed, status: int|null}
     */
    public static function fail($data = null, $status = null)
    {
        return ['success' => false, 'data' => $data, 'status' => $status];
    }

    /**
     * Handler AJAX: in kết quả của lõi y như trước (cùng khoá, cùng mã HTTP) rồi dừng.
     *
     * @internal
     */
    public static function send(array $result)
    {
        if (!empty($result['success'])) {
            wp_send_json_success($result['data'], $result['status']);
        }
        wp_send_json_error($result['data'], $result['status']);
    }

    // ── API công khai ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Danh sách hoá đơn theo bộ lọc (màn "Kiểm tra gửi thuế").
     *
     * @param array $filters date_from, date_to (YYYY-MM-DD), status (all|success|failed|pending|unsent),
     *                       age_filter (all|under24|over24), document_type (all|sale|return_adjustment), limit
     * @return array|WP_Error
     */
    public static function list_statuses(array $filters = [])
    {
        $keys = ['date_from', 'date_to', 'status', 'age_filter', 'document_type', 'limit'];
        return self::run([self::plugin(), 'core_pos_list_statuses'], array_intersect_key($filters, array_flip($keys)));
    }

    /** Dòng hàng + người mua để nhân viên xem trước khi phát hành. @return array|WP_Error */
    public static function items_for_review($sale_ledger_id)
    {
        return self::run([self::plugin(), 'core_pos_get_items_for_review'], ['sale_ledger_id' => (int) $sale_ledger_id]);
    }

    /**
     * Cập nhật cờ "khuyến mãi dưới 24 tháng" của dòng hàng.
     *
     * @param array $flags [[ 'item_id' => int, 'danger_flag' => 0|1 ], ...]
     * @return array|WP_Error
     */
    public static function update_danger_flags($sale_ledger_id, array $flags)
    {
        return self::run([self::plugin(), 'core_pos_update_danger_flags'], [
            'sale_ledger_id' => (int) $sale_ledger_id,
            'item_flags' => array_values($flags),
        ]);
    }

    /**
     * Phát hành hoá đơn + gửi CQT cho phiếu bán (nút "Gửi lên cục thuế").
     *
     * @param array $options customer_override (mảng customer_name, customer_company_name, customer_tax_code,
     *                       customer_address, customer_phone, customer_email), excluded_item_ids (int[]),
     *                       force_under24 (bool — nhân viên đã xác nhận cảnh báo hàng chính dưới 24 tháng)
     * @return array|WP_Error
     */
    public static function issue($sale_ledger_id, array $options = [])
    {
        $in = ['sale_ledger_id' => (int) $sale_ledger_id] + self::sale_options($options);
        $in['force_under24'] = !empty($options['force_under24']);
        return self::run([self::plugin(), 'core_send_from_sale'], $in);
    }

    /**
     * Gửi lại: đã phát hành → chỉ gửi lại CQT; chưa → chạy lại cả luồng (không tạo hoá đơn trùng).
     *
     * @param array $options customer_override, excluded_item_ids như issue()
     * @return array|WP_Error
     */
    public static function retry($sale_ledger_id, array $options = [])
    {
        return self::run([self::plugin(), 'core_pos_retry_invoice'], ['sale_ledger_id' => (int) $sale_ledger_id] + self::sale_options($options));
    }

    /**
     * Tra số hoá đơn trên Viettel theo transactionUuid / nhập tay / đền bù.
     *
     * @param array $options manual_invoice_no (string), compensate (bool)
     * @return array|WP_Error
     */
    public static function recover_invoice_no($sale_ledger_id, array $options = [])
    {
        $in = ['sale_ledger_id' => (int) $sale_ledger_id];
        if (isset($options['manual_invoice_no']) && (string) $options['manual_invoice_no'] !== '') {
            $in['manual_invoice_no'] = (string) $options['manual_invoice_no'];
        }
        if (!empty($options['compensate'])) {
            $in['compensate'] = '1';
        }
        return self::run([self::plugin(), 'core_pos_recover_invoice_no'], $in);
    }

    /** Gắn số hoá đơn thay thế lập ngoài (cần mật khẩu xác nhận). @return array|WP_Error */
    public static function import_replacement($sale_ledger_id, $replacement_invoice_no, $password)
    {
        return self::run([self::plugin(), 'core_pos_import_replacement'], [
            'sale_ledger_id' => (int) $sale_ledger_id,
            'replacement_invoice_no' => (string) $replacement_invoice_no,
            'password' => (string) $password,
        ]);
    }

    /** Đổi ngày hoá đơn (dd/mm/yyyy, cần mật khẩu xác nhận). @return array|WP_Error */
    public static function update_invoice_date($sale_ledger_id, $invoice_date, $password)
    {
        return self::run([self::plugin(), 'core_pos_update_invoice_date'], [
            'sale_ledger_id' => (int) $sale_ledger_id,
            'invoice_date' => (string) $invoice_date,
            'password' => (string) $password,
        ]);
    }

    /** Gửi email hoá đơn (kênh theo cấu hình website: SMTP / Viettel). @return array|WP_Error */
    public static function send_email($sale_ledger_id, $to_email)
    {
        return self::run([self::plugin(), 'core_pos_send_invoice_email'], [
            'sale_ledger_id' => (int) $sale_ledger_id,
            'to_email' => (string) $to_email,
        ]);
    }

    /**
     * Gửi email nhiều địa chỉ qua portal Viettel.
     *
     * @param string[]|string $to_emails mảng email hoặc chuỗi ngăn bởi ; ,
     * @return array|WP_Error
     */
    public static function send_email_portal($sale_ledger_id, $to_emails)
    {
        return self::run([self::plugin(), 'core_pos_send_invoice_email_portal'], [
            'sale_ledger_id' => (int) $sale_ledger_id,
            'to_emails' => is_array($to_emails) ? implode(';', array_map('strval', $to_emails)) : (string) $to_emails,
        ]);
    }

    /** PDF hoá đơn bán (file_bytes_base64...). @return array|WP_Error */
    public static function invoice_pdf($sale_ledger_id)
    {
        return self::run([self::plugin(), 'core_pos_preview_invoice_pdf'], ['sale_ledger_id' => (int) $sale_ledger_id]);
    }

    /** PDF hoá đơn điều chỉnh giảm của phiếu hoàn. @return array|WP_Error */
    public static function adjustment_pdf($return_ledger_id)
    {
        return self::run([self::plugin(), 'core_pos_preview_adjustment_pdf'], ['return_ledger_id' => (int) $return_ledger_id]);
    }

    /** Nhật ký gửi CQT gần nhất của phiếu bán (limit 1..100, sai → 30). @return array|WP_Error */
    public static function sale_debug_log($sale_ledger_id, $limit = 30)
    {
        return self::run([self::plugin(), 'core_get_sale_debug_log'], ['sale_ledger_id' => (int) $sale_ledger_id, 'limit' => (int) $limit]);
    }

    /** Xem trước hoá đơn điều chỉnh giảm (KHÔNG phát hành). @return array|WP_Error */
    public static function preview_return_adjustment($queue_id)
    {
        return self::run([self::return_adjustment(), 'core_preview'], ['queue_id' => (int) $queue_id]);
    }

    /** Phát hành hoá đơn điều chỉnh giảm + gửi CQT (sau khi người dùng xác nhận bản xem trước). @return array|WP_Error */
    public static function confirm_return_adjustment($queue_id)
    {
        return self::run([self::return_adjustment(), 'core_confirm'], ['queue_id' => (int) $queue_id]);
    }

    // ── Nội bộ ──────────────────────────────────────────────────────────────────────────────────────────────────────

    private static function plugin()
    {
        return class_exists('TGS_Viettel_Invoice_Plugin') ? TGS_Viettel_Invoice_Plugin::instance() : null;
    }

    private static function return_adjustment()
    {
        return class_exists('TGS_Viettel_Invoice_Return_Adjustment') ? TGS_Viettel_Invoice_Return_Adjustment::instance() : null;
    }

    /** customer_override + excluded_item_ids dạng mảng → đúng trường form AJAX mà lõi đọc. */
    private static function sale_options(array $options)
    {
        $in = [];
        if (isset($options['customer_override'])) {
            $c = $options['customer_override'];
            $in['customer_override'] = is_array($c) ? ($c ? $c : '{}') : (string) $c;
        }
        if (isset($options['excluded_item_ids'])) {
            $ids = $options['excluded_item_ids'];
            $in['excluded_item_ids'] = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : (string) $ids;
        }
        return $in;
    }

    /**
     * Đổi đầu vào dạng mảng thường → trường form y như trình duyệt gửi qua AJAX (bool → '1'/'', mảng → JSON, rồi slash như
     * $_POST của WordPress) — lõi đọc đúng như đọc $_POST — rồi gọi lõi và đổi kết quả.
     *
     * @return array|WP_Error
     */
    private static function run($callable, array $input)
    {
        if (!is_object($callable[0]) || !method_exists($callable[0], $callable[1])) {
            return new WP_Error('tgs_viettel_invoice_unavailable', 'Plugin hoá đơn Viettel chưa sẵn sàng.', ['status' => 503]);
        }
        $fields = [];
        foreach ($input as $k => $v) {
            $fields[(string) $k] = is_bool($v) ? ($v ? '1' : '') : (is_scalar($v) || $v === null ? (string) $v : wp_json_encode($v));
        }
        $result = call_user_func($callable, wp_slash($fields));
        return self::to_service_result($result);
    }

    /** @return array|WP_Error */
    private static function to_service_result($result)
    {
        if (!is_array($result) || !array_key_exists('success', $result)) {
            return new WP_Error(self::ERROR_CODE, 'Thao tác hoá đơn không thành công.', ['status' => 500, 'http_status' => 500, 'details' => []]);
        }
        $data = $result['data'];
        $status = isset($result['status']) ? (int) $result['status'] : null;
        if ($result['success']) {
            return is_array($data) ? $data : (array) $data;
        }
        $details = is_array($data) ? $data : [];
        $message = isset($details['message']) && is_scalar($details['message']) ? (string) $details['message'] : 'Thao tác không thành công.';
        return new WP_Error(self::ERROR_CODE, $message, [
            'status' => ($status !== null && $status >= 400) ? $status : 422,
            'http_status' => $status,
            'details' => $details,
        ]);
    }
}
