<?php
/**
 * Cài đặt hoá đơn điện tử kéo từ open-ketoan (THUE03, module hoa-don-dien-tu — kế hoạch 90 S-HD-4, phiếu NT-BH-048 M9).
 *
 *   Nguồn   GET <kế toán>/api/v1/m/hoa-don-dien-tu/cai-dat/export?branchCode=<mã chi nhánh của blog>
 *           — gọi bằng client của Cổng kế toán (TGS_Acc_Api_Client::for_blog: đúng URL + khoá của blog, chỉ ĐỌC lớp đó, không sửa cầu nối).
 *           Khoá kt_ cần phạm vi reports:read. Phản hồi KHÔNG có mật khẩu / token.
 *   Đệm     site option `tgs_viettel_ketoan_pull_<blog>`: bản kéo thành công gần nhất + lần thử / lỗi gần nhất. Kế toán tắt / mạng lỗi
 *           thì vẫn dùng bản đã kéo (P-0); chưa kéo được lần nào thì dùng cụm / cấu hình website như cũ.
 *   Làm mới WP-Cron mỗi giờ (site chính, kéo mọi blog) + nút "Kéo lại từ kế toán" ở màn Cấu hình Viettel Invoice.
 *   Áp      Ô thường kế toán khai (người bán, API URL, xác thực, username, SSL, mẫu số, ký hiệu, thanh toán, tự động, portal) ĐÈ lên ô
 *           tương ứng của cụm / cấu hình website — chỉ khi sổ ở kế toán "đang dùng" và chi nhánh đang dùng. Mật khẩu Viettel, access token,
 *           mật khẩu portal LUÔN lấy từ website (Hoàng chốt 26/11 — đọc bí mật qua khoá làm sau).
 */
// [2026-11-27 12:10 AM Võ Hoàng] S-HD-4 — kéo cài đặt hoá đơn điện tử khai ở kế toán (THUE03) về website; mật khẩu tạm giữ ở website

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_Viettel_Invoice_Ketoan_Settings
{
    const PATH = '/api/v1/m/hoa-don-dien-tu/cai-dat/export';
    const OPTION_PREFIX = 'tgs_viettel_ketoan_pull_';
    const CRON_HOOK = 'tgs_viettel_ketoan_pull_cron';
    const AJAX = 'tgs_viettel_ketoan_pull';

    /** Ô kế toán → khoá cấu hình của plugin. Không có ô bí mật (password / access_token / portal_password ở lại website). */
    const MAP = [
        'tenPhapLy'        => 'company_name',
        'mst'              => 'supplier_tax_code',
        'diaChi'           => 'company_address',
        'dienThoai'        => 'company_phone',
        'apiBaseUrl'       => 'api_base_url',
        'authMode'         => 'auth_mode',
        'username'         => 'username',
        'sslVerify'        => 'verify_ssl',
        'templateCode'     => 'default_template_code',
        'invoiceSeries'    => 'default_invoice_series',
        'paymentMethod'    => 'default_payment_method',
        'autoEnabled'      => 'auto_enabled',
        'autoMode'         => 'auto_mode',
        'portalEnabled'    => 'portal_enabled',
        'portalAccount'    => 'portal_username',
        'portalSupplierId' => 'portal_supplier_id',
    ];
    const BOOL_FIELDS = ['sslVerify', 'autoEnabled', 'portalEnabled'];

    /** Ô trên màn website bị khoá khi blog đang dùng cài đặt kế toán (id thẻ input). Ô mật khẩu không có ở đây. */
    const LOCKED_INPUTS = [
        'vi_api_base_url', 'vi_auth_mode', 'vi_username', 'vi_verify_ssl', 'vi_default_template_code', 'vi_default_invoice_series',
        'vi_default_payment_method', 'vi_auto_enabled', 'vi_auto_mode',
        'vic_tax_code', 'vic_legal_name', 'vic_legal_address', 'vic_legal_phone', 'vic_api_base_url', 'vic_auth_mode', 'vic_username',
        'vic_verify_ssl', 'vic_template_code', 'vic_invoice_series', 'vic_payment_method', 'vic_auto_enabled', 'vic_auto_mode',
        'vic_portal_enabled', 'vic_portal_username', 'vic_portal_supplier_id',
    ];

    /** Chỉ cho test: thay lời gọi HTTP. fn(int $blog, string $branch): array{data:array}|WP_Error */
    public static $fetch = null;
    /** Chỉ cho test: thay mã chi nhánh của blog. fn(int $blog): string */
    public static $branch = null;

    public static function init()
    {
        add_filter('cron_schedules', [__CLASS__, 'schedules']);
        add_action(self::CRON_HOOK, [__CLASS__, 'pull_all']);
        add_action('wp_ajax_' . self::AJAX, [__CLASS__, 'ajax_pull']);
        add_action('init', [__CLASS__, 'schedule']);
    }

    public static function schedules($s)
    {
        if (!isset($s['hourly'])) {
            $s['hourly'] = ['interval' => HOUR_IN_SECONDS, 'display' => 'Mỗi giờ'];
        }
        return $s;
    }

    /** Lịch mỗi giờ, chỉ đặt ở site chính (một lượt kéo cho mọi blog). */
    public static function schedule()
    {
        if (is_multisite() && !is_main_site()) {
            return;
        }
        if (!self::bridge_ready() && self::$fetch === null) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'hourly', self::CRON_HOOK);
        }
    }

    /** Có Cổng kế toán (open-ketoan-bridge) để biết URL, khoá, mã chi nhánh. */
    public static function bridge_ready()
    {
        return class_exists('TGS_Acc_Api_Client') && class_exists('TGS_Acc_Source');
    }

    public static function branch_of($blog_id)
    {
        if (is_callable(self::$branch)) {
            return (string) call_user_func(self::$branch, (int) $blog_id);
        }
        return class_exists('TGS_Acc_Source') ? (string) TGS_Acc_Source::shop_code((int) $blog_id) : '';
    }

    private static function option($blog_id)
    {
        return self::OPTION_PREFIX . (int) $blog_id;
    }

    /** Bản đệm của blog: ['row' => …|null, 'pulled_at', 'branch', 'config_version', 'configured', 'last_try', 'last_error'] */
    public static function cached($blog_id)
    {
        $v = get_site_option(self::option($blog_id), []);
        return is_array($v) ? $v : [];
    }

    /**
     * Kéo cài đặt của một blog. Thành công → ghi đè bản đệm; lỗi → giữ bản cũ, chỉ ghi lần thử + lỗi.
     * @return array bản đệm sau lượt kéo (có 'ok' => bool, 'message')
     */
    public static function pull($blog_id)
    {
        $blog_id = (int) $blog_id;
        $cache = self::cached($blog_id);
        $cache['last_try'] = current_time('mysql');
        $branch = self::branch_of($blog_id);
        if ($branch === '') {
            return self::fail($blog_id, $cache, 'Shop chưa có mã chi nhánh kế toán (Cổng kế toán → Tổng quan / Cấu hình phân cấp).');
        }
        if (is_callable(self::$fetch)) {
            $res = call_user_func(self::$fetch, $blog_id, $branch);
        } elseif (self::bridge_ready()) {
            $client = TGS_Acc_Api_Client::for_blog($blog_id);
            $res = $client->ready() ? $client->get(self::PATH, ['branchCode' => $branch]) : new WP_Error('ketoan_not_configured', 'Cổng kế toán chưa khai địa chỉ / khoá API.');
        } else {
            return self::fail($blog_id, $cache, 'Chưa bật plugin Cổng kế toán (open-ketoan-bridge).');
        }
        if (is_wp_error($res)) {
            $st = (int) ($res->get_error_data()['status'] ?? 0);
            $msg = $res->get_error_message();
            if ($st === 403) {
                $msg = 'Khoá API của Cổng kế toán chưa có phạm vi "Đọc báo cáo, sổ sách" (reports:read) — ' . $msg;
            } elseif ($st === 404) {
                $msg = 'Kế toán chưa có module Hoá đơn điện tử (THUE03) hoặc chưa có chi nhánh ' . $branch . ' — ' . $msg;
            }
            return self::fail($blog_id, $cache, $msg);
        }
        $data = (array) ($res['data'] ?? []);
        $rows = array_values(array_filter((array) ($data['chiNhanh'] ?? []), static function ($r) use ($branch) {
            return is_array($r) && (string) ($r['branchCode'] ?? '') === $branch;
        }));
        $cache = [
            'row' => $rows[0] ?? null,
            'configured' => !empty($data['configured']),
            'config_version' => (string) ($data['configVersion'] ?? ''),
            'branch' => $branch,
            'pulled_at' => current_time('mysql'),
            'last_try' => $cache['last_try'],
            'last_error' => '',
        ];
        update_site_option(self::option($blog_id), $cache);
        $cache['ok'] = true;
        $cache['message'] = self::applies($cache) ? 'Đã kéo cài đặt chi nhánh ' . $branch . ' từ kế toán.' : self::reason($cache);
        return $cache;
    }

    private static function fail($blog_id, array $cache, $msg)
    {
        $cache['last_error'] = (string) $msg;
        update_site_option(self::option($blog_id), $cache);
        $cache['ok'] = false;
        $cache['message'] = (string) $msg;
        return $cache;
    }

    /** Bản đệm có đủ điều kiện để đè cấu hình website không. */
    public static function applies(array $cache)
    {
        $row = $cache['row'] ?? null;
        return !empty($cache['configured']) && is_array($row)
            && (string) ($row['trangThai'] ?? '') === 'dang-dung'
            && ($row['active'] ?? true) !== false;
    }

    public static function reason(array $cache)
    {
        if (empty($cache['pulled_at'])) {
            return 'Chưa kéo được cài đặt từ kế toán — đang dùng cụm / cấu hình website.';
        }
        if (empty($cache['configured'])) {
            return 'Kế toán chưa khai Hoá đơn điện tử (THUE03) — đang dùng cụm / cấu hình website.';
        }
        if (!is_array($cache['row'] ?? null)) {
            return 'Kế toán không có chi nhánh ' . ($cache['branch'] ?? '') . ' — đang dùng cụm / cấu hình website.';
        }
        if ((string) ($cache['row']['trangThai'] ?? '') !== 'dang-dung') {
            return 'Cài đặt ở kế toán chưa ở trạng thái "Đang dùng" — đang dùng cụm / cấu hình website.';
        }
        return 'Chi nhánh ' . ($cache['branch'] ?? '') . ' ngừng dùng ở kế toán — đang dùng cụm / cấu hình website.';
    }

    /**
     * Đè ô thường từ kế toán lên cấu hình đã giải (cụm / legacy) của blog. Mật khẩu / token / mật khẩu portal giữ nguyên của website.
     * Ghi `_ketoan` để màn hình / nhật ký biết nguồn.
     */
    public static function overlay($blog_id, array $settings)
    {
        $cache = self::cached($blog_id);
        if (!self::applies($cache)) {
            $settings['_ketoan'] = ['applied' => false, 'reason' => self::reason($cache), 'last_error' => (string) ($cache['last_error'] ?? '')];
            return $settings;
        }
        $row = (array) $cache['row'];
        $fields = [];
        foreach (self::MAP as $from => $to) {
            if (!array_key_exists($from, $row)) {
                continue;
            }
            $v = $row[$from];
            if (in_array($from, self::BOOL_FIELDS, true)) {
                $v = !empty($v) ? 1 : 0;
            } else {
                $v = is_scalar($v) ? trim((string) $v) : '';
                if ($v === '') {
                    continue; // ô kế toán để trống: giữ giá trị website
                }
            }
            $settings[$to] = $v;
            $fields[] = $to;
        }
        $settings['_ketoan'] = [
            'applied' => true,
            'branch' => (string) ($cache['branch'] ?? ''),
            'pulled_at' => (string) ($cache['pulled_at'] ?? ''),
            'config_version' => (string) ($cache['config_version'] ?? ''),
            'fields' => $fields,
            'last_error' => (string) ($cache['last_error'] ?? ''),
        ];
        return $settings;
    }

    /** Cron: kéo cho mọi blog (multisite) hoặc site hiện tại. */
    public static function pull_all()
    {
        $ids = is_multisite() ? array_map('intval', get_sites(['fields' => 'ids', 'number' => 0, 'archived' => 0, 'deleted' => 0])) : [get_current_blog_id()];
        $out = [];
        foreach ($ids as $id) {
            if (is_multisite() && (int) $id === (int) get_main_site_id()) {
                continue;
            }
            if (self::branch_of($id) === '') {
                continue;
            }
            $r = self::pull($id);
            $out[$id] = ['ok' => !empty($r['ok']), 'message' => (string) ($r['message'] ?? '')];
        }
        return $out;
    }

    /** Ai được bấm "Kéo lại từ kế toán": super admin (cả mạng) hoặc quản trị shop (chỉ shop của mình). */
    private static function can_pull_all()
    {
        return is_multisite() ? is_super_admin() : current_user_can('manage_options');
    }

    public static function ajax_pull()
    {
        check_ajax_referer(self::AJAX, 'nonce');
        if (!current_user_can('manage_options') && !self::can_pull_all()) {
            wp_send_json_error(['message' => 'Bạn không có quyền kéo cài đặt hoá đơn điện tử.'], 403);
        }
        $scope = sanitize_text_field(wp_unslash($_POST['scope'] ?? 'blog'));
        if ($scope === 'all' && self::can_pull_all()) {
            wp_send_json_success(['all' => self::pull_all()]);
        }
        $r = self::pull(get_current_blog_id());
        $r['ok'] ? wp_send_json_success(self::public_view($r)) : wp_send_json_error(self::public_view($r));
    }

    /** Phần được trả về trình duyệt (không có gì bí mật — export vốn không có bí mật). */
    private static function public_view(array $c)
    {
        return [
            'message' => (string) ($c['message'] ?? ''),
            'branch' => (string) ($c['branch'] ?? ''),
            'pulled_at' => (string) ($c['pulled_at'] ?? ''),
            'applied' => self::applies($c),
        ];
    }

    /** Khung trạng thái + nút "Kéo lại từ kế toán" ở đầu màn Cấu hình Viettel Invoice; khoá ô kế toán đang khai. */
    public static function render_panel()
    {
        $blog = get_current_blog_id();
        $cache = self::cached($blog);
        $on = self::applies($cache);
        $branch = (string) ($cache['branch'] ?? self::branch_of($blog));
        $nonce = wp_create_nonce(self::AJAX);
        $ajax = admin_url('admin-ajax.php');
        ?>
        <div class="alert <?php echo $on ? 'alert-success' : 'alert-secondary'; ?> mb-3" id="vi-ketoan-panel">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <?php if ($on) : ?>
                        <strong>Đang dùng cài đặt khai ở kế toán (THUE03) — kéo lúc <?php echo esc_html((string) $cache['pulled_at']); ?></strong>
                        <div class="small">Chi nhánh <b><?php echo esc_html($branch); ?></b>: người bán, tài khoản Viettel (URL, xác thực, username, SSL), mẫu số, ký hiệu, thanh toán, tự động, portal lấy theo kế toán — sửa ở kế toán, các ô đó trên màn này chỉ xem.
                            <b>Mật khẩu Viettel, access token, mật khẩu portal vẫn khai ở website</b> (cụm / cấu hình bên dưới).</div>
                    <?php else : ?>
                        <strong>Hoá đơn điện tử: <?php echo esc_html(self::reason($cache)); ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($cache['last_error'])) : ?>
                        <div class="small text-danger">Lần kéo gần nhất (<?php echo esc_html((string) ($cache['last_try'] ?? '')); ?>) lỗi: <?php echo esc_html((string) $cache['last_error']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="vi-ketoan-pull" title="Kéo ngay cài đặt hoá đơn điện tử của shop này từ kế toán (tự kéo lại mỗi giờ)">Kéo lại từ kế toán</button>
                    <?php if (self::can_pull_all()) : ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="vi-ketoan-pull-all" title="Kéo cho mọi shop trong mạng">Kéo cho mọi shop</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="small mt-1" id="vi-ketoan-pull-msg"></div>
        </div>
        <script>
        (function () {
            var locked = <?php echo wp_json_encode($on ? self::LOCKED_INPUTS : []); ?>;
            function lock() {
                locked.forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el || el.dataset.ketoanLocked) return;
                    el.dataset.ketoanLocked = '1';
                    if (el.tagName === 'SELECT' || el.type === 'checkbox') { el.disabled = true; } else { el.readOnly = true; }
                    el.title = 'Khai ở kế toán (THUE03) — sửa ở kế toán';
                    el.classList.add('bg-light');
                });
            }
            lock();
            // Hộp sửa cụm dựng lại ô khi mở: khoá lại mỗi lần DOM đổi.
            if (locked.length && window.MutationObserver) {
                new MutationObserver(lock).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
            }
            function pull(scope) {
                var msg = document.getElementById('vi-ketoan-pull-msg');
                msg.textContent = 'Đang kéo…';
                var body = new URLSearchParams({ action: <?php echo wp_json_encode(self::AJAX); ?>, nonce: <?php echo wp_json_encode($nonce); ?>, scope: scope });
                fetch(<?php echo wp_json_encode($ajax); ?>, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.data && j.data.all) {
                            var n = 0, bad = 0; Object.keys(j.data.all).forEach(function (k) { n++; if (!j.data.all[k].ok) bad++; });
                            msg.textContent = 'Đã kéo ' + n + ' shop' + (bad ? (', ' + bad + ' shop lỗi — mở từng shop để xem') : '') + '. Tải lại trang để thấy cài đặt mới.';
                        } else {
                            msg.textContent = ((j && j.data && j.data.message) || 'Không kéo được.') + (j && j.success ? ' Tải lại trang để thấy cài đặt mới.' : '');
                        }
                    })
                    .catch(function () { msg.textContent = 'Không gọi được máy chủ website.'; });
            }
            var b = document.getElementById('vi-ketoan-pull'); if (b) b.addEventListener('click', function () { pull('blog'); });
            var a = document.getElementById('vi-ketoan-pull-all'); if (a) a.addEventListener('click', function () { pull('all'); });
        })();
        </script>
        <?php
    }
}
