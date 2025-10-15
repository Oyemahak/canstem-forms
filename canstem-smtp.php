<?php
/* ---------- SMTP (Gmail App Password) ---------- */
add_action('phpmailer_init', function($phpmailer){
    $phpmailer->isSMTP();
    $phpmailer->Host       = 'smtp.gmail.com';
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = 587;
    $phpmailer->SMTPSecure = 'tls';

    // IMPORTANT: 16-char app password WITHOUT spaces
    $phpmailer->Username   = 'canstem.frontdesk@canstemeducation.com';
    $phpmailer->Password   = 'persoqionuoycbkl';

    $phpmailer->setFrom('canstem.frontdesk@canstemeducation.com', 'CanSTEM Front Desk', false);

    // Debug to PHP error_log while finishing setup
    $phpmailer->SMTPDebug   = 0; // set 2 temporarily if you need to see SMTP dialog
    $phpmailer->Debugoutput = function($str,$level){ error_log("SMTP[$level] $str"); };
}, 999);

add_action('wp_mail_failed', function($wp_error){
    if (is_wp_error($wp_error)) error_log('wp_mail_failed: '.$wp_error->get_error_message());
});

/* ---------- AJAX Endpoint ---------- */
add_action('wp_ajax_canstem_form_email', 'canstem_form_email');
add_action('wp_ajax_nopriv_canstem_form_email', 'canstem_form_email');

function canstem_form_email(){
    try {
        if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            wp_send_json_error(['error'=>'Invalid method'], 405);
        }

        // Accept JSON or form-encoded/multipart
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || !count($data)) $data = $_POST;

        $sx = function($v){ return sanitize_text_field(wp_unslash($v ?? '')); };
        $tx = function($v){ return sanitize_textarea_field(wp_unslash($v ?? '')); };

        $type         = $sx($data['type'] ?? 'Request');
        $name         = $sx($data['name'] ?? '');
        $email        = sanitize_email($data['email'] ?? '');
        $phone        = $sx($data['phone'] ?? '');
        $grade        = $sx($data['grade'] ?? '');
        $courseCode   = $sx($data['courseCode'] ?? '');
        $newcourse    = $sx($data['newcourse'] ?? '');
        $currentMode  = $sx($data['currentMode'] ?? '');
        $mode         = $sx($data['mode'] ?? '');
        $preferred    = $sx($data['preferredDate'] ?? '');
        $reason       = $tx($data['reason'] ?? '');
        $studentSig   = $sx($data['studentSig'] ?? '');
        $parentSig    = $sx($data['parentSig'] ?? '');

        // accept '1', 'true', 'yes', 'Yes'
        $yn = static function($v){
            $v = strtolower(trim((string)$v));
            return in_array($v, ['1','true','yes','y'], true) ? 'Yes' : 'No';
        };
        $paidConfirmed = $yn($data['paidConfirmed'] ?? '');

        if (!$name || !$email) wp_send_json_error(['error'=>'Missing required fields'], 400);

        // ---------- Subject ----------
        // e.g. "Final Exam — Mahak Patel — MHF4U"
        $subject_map = [
            'Final Exam'    => 'Final Exam',
            'Withdrawal'    => 'Withdrawal',
            'Change Course' => 'Change Course',
            'Mode Switch'   => 'Mode Switch',
            'Request'       => 'Request'
        ];
        $subject_prefix = $subject_map[$type] ?? $type;
        $subject_suffix = ($type === 'Change Course' && $newcourse) ? " → $newcourse" : '';
        $subject = sprintf('%s — %s — %s%s',
            $subject_prefix,
            $name,
            $courseCode ?: 'N/A',
            $subject_suffix
        );

        // ---------- Email Body ----------
        $rows = [];
        // Header line shown at top (you asked: “Final Exam Request — Mode”)
        $nice_header = $subject_prefix . ' Request';
        if ($type === 'Final Exam' && $mode) $nice_header .= ' — ' . $mode;

        $rows[] = tr_sep();
        $rows[] = tr('Request Type', $type);
        $rows[] = tr('Submitted At', wp_date('D, M j, Y · g:i a', time(), wp_timezone()));
        $rows[] = tr_sep();
        $rows[] = tr('Student Name', $name);
        $rows[] = tr('Student Email', $email);
        $rows[] = tr('Phone', $phone);
        $rows[] = tr_sep();
        if ($preferred)  $rows[] = tr('Preferred Exam Date', $preferred);
        if ($grade)      $rows[] = tr('Grade', $grade);
        if ($courseCode) $rows[] = tr('Course / Code', $courseCode);
        if ($newcourse)  $rows[] = tr('New Requested Course', $newcourse);
        if ($currentMode)$rows[] = tr('Current Mode', $currentMode);
        if ($mode)       $rows[] = tr('Selected/Requested Mode', $mode);
        $rows[] = tr_sep();
        if ($reason)     $rows[] = tr('Reason', nl2br(esc_html($reason)));
        if ($type !== 'Withdrawal') $rows[] = tr('Payment Marked Done', $paidConfirmed);
        $rows[] = tr_sep();
        $rows[] = tr('Student Signature', $studentSig ?: '(missing)');
        if ($parentSig)  $rows[] = tr('Parent/Guardian Signature', $parentSig);

        $styles = '<style>
            body{font-family:Arial,Helvetica,sans-serif;color:#0f172a}
            .wrap{max-width:720px;margin:0 auto;padding:16px}
            h2{margin:0 0 12px 0;color:#001161}
            table{border-collapse:separate;border-spacing:0;width:100%;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
            th,td{padding:10px 12px;vertical-align:top;font-size:14px}
            th{background:#f8fafc;text-align:left;width:240px;color:#0b1324}
            tr+tr td,tr+tr th{border-top:1px solid #e5e7eb}
            .small{margin-top:10px;color:#475569;font-size:12px}
        </style>';

        $body = $styles .
            '<div class="wrap">'.
            '<h2>'.esc_html($nice_header).'</h2>'.
            '<table>'.implode('', $rows).'</table>'.
            '<p class="small">Sent automatically from a CanSTEM form.</p>'.
            '</div>';

        // ---------- File attachments (if any) ----------
        $attachments = [];
        $uploads = wp_upload_dir();
        $tmpdir  = trailingslashit($uploads['basedir']) . 'canstem-temp';
        if (!file_exists($tmpdir)) wp_mkdir_p($tmpdir);

        foreach (['w_files','c_up_payment','c_prereq','c_files','m_up_payment','m_files'] as $field) {
            if (empty($_FILES[$field]['name'])) continue;
            $names = $_FILES[$field]['name'];
            $tmps  = $_FILES[$field]['tmp_name'];
            if (!is_array($names)) { $names = [$names]; $tmps = [$tmps]; }
            foreach ($names as $i => $n) {
                if (empty($tmps[$i]) || !is_uploaded_file($tmps[$i])) continue;
                $safe = sanitize_file_name($n);
                $dest = trailingslashit($tmpdir) . wp_unique_filename($tmpdir, $safe);
                if (move_uploaded_file($tmps[$i], $dest)) $attachments[] = $dest;
            }
        }

        $from = 'canstem.frontdesk@canstemeducation.com';
        $headers = ['Content-Type: text/html; charset=UTF-8', 'From: CanSTEM Front Desk <'.$from.'>'];
        if ($email) $headers[] = 'Reply-To: '.$name.' <'.$email.'>';

        $to   = 'canstem.frontdesk@canstemeducation.com';
        $sent = wp_mail($to, $subject, $body, $headers, $attachments);

        foreach ($attachments as $p) { @unlink($p); }

        if (!$sent) {
            error_log('canstem_form_email: wp_mail failed');
            wp_send_json_error(['error'=>'Mail failed (SMTP)'], 500);
        }

        wp_send_json(['ok'=>true]);

    } catch (Throwable $e){
        error_log('canstem_form_email exception: '.$e->getMessage());
        wp_send_json_error(['error'=>'Server error'], 500);
    }
}

/* helpers */
function tr($label,$val){ return '<tr><th>'.esc_html($label).'</th><td>'.$val.'</td></tr>'; }
function tr_sep(){ return '<tr><td colspan="2" style="padding:0"><hr style="border:none;border-top:1px solid #e5e7eb;margin:8px 0"></td></tr>'; }