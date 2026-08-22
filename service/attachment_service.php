<?php

/**
 * Messenger — PM image attachments
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class attachment_service
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var \phpbb\language\language */
    protected $language;

    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\request\request */
    protected $request;

    /** @var string */
    protected $table_prefix;

    /** @var string */
    protected $t_attachments;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\config\config $config,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\language\language $language,
        \phpbb\controller\helper $helper,
        \phpbb\request\request $request,
        $table_prefix
    ) {
        $this->db            = $db;
        $this->config        = $config;
        $this->user          = $user;
        $this->auth          = $auth;
        $this->language      = $language;
        $this->helper        = $helper;
        $this->request       = $request;
        $this->table_prefix  = $table_prefix;
        $this->t_attachments = $table_prefix . 'attachments';
    }

    public function can_upload_images()
    {
        return !empty($this->config['allow_pm_attach'])
            && $this->auth->acl_get('u_sendpm');
    }

    public function can_show_image_upload()
    {
        return $this->auth->acl_get('u_sendpm');
    }

    /**
     * @return array{success: bool, attach_id?: int, real_filename?: string, preview_url?: string, error?: string}
     */
    public function upload_image($form_name = 'fileupload')
    {
        if (!$this->can_upload_images())
        {
            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_NOT_ALLOWED'];
        }

        $this->language->add_lang('posting');

        if (!$this->is_upload_storage_ready())
        {
            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_STORAGE_UNAVAILABLE'];
        }

        $image_data = trim($this->request->raw_variable('image_data', '', \phpbb\request\request_interface::POST));
        if ($image_data === '' && $this->is_oversized_request())
        {
            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_PAYLOAD_TOO_LARGE'];
        }

        if ($image_data !== '')
        {
            $filename = trim($this->request->variable('image_filename', 'image.jpg', true, \phpbb\request\request_interface::POST));
            if ($filename === '')
            {
                $filename = 'image.jpg';
            }

            return $this->upload_image_from_base64($image_data, $filename);
        }

        $form_name = $this->resolve_upload_field($form_name);
        if ($form_name === '')
        {
            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_NO_FILE'];
        }

        $upload = $this->get_upload_file($form_name);
        $upload_error = (int) ($upload['error'] ?? UPLOAD_ERR_OK);
        if ($upload_error !== UPLOAD_ERR_OK)
        {
            return ['success' => false, 'error' => $this->format_php_upload_error($upload_error)];
        }

        global $phpbb_container;

        /** @var \phpbb\attachment\manager $attachment_manager */
        $attachment_manager = $phpbb_container->get('attachment.manager');
        $filedata = $attachment_manager->upload($form_name, 0, false, '', true);

        return $this->finalize_upload($attachment_manager, $filedata);
    }

    /**
     * @return array{success: bool, attach_id?: int, real_filename?: string, preview_url?: string, error?: string}
     */
    protected function upload_image_from_base64($base64, $filename)
    {
        $base64 = preg_replace('#\s+#', '', (string) $base64);
        if ($base64 === '')
        {
            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_NO_FILE'];
        }

        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '')
        {
            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_NO_FILE'];
        }

        $max = (int) $this->config['max_filesize_pm'];
        if ($max > 0 && strlen($binary) > $max)
        {
            return ['success' => false, 'error' => $this->language->lang('ATTACHMENT_TOO_LARGE')];
        }

        $temp = tempnam(sys_get_temp_dir(), 'msgr_');
        if ($temp === false || @file_put_contents($temp, $binary) === false)
        {
            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_FAILED'];
        }

        $mimetype = 'application/octet-stream';
        if (function_exists('finfo_open'))
        {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo)
            {
                $detected = finfo_file($finfo, $temp);
                if (is_string($detected) && $detected !== '')
                {
                    $mimetype = $detected;
                }
                finfo_close($finfo);
            }
        }

        global $phpbb_container;

        /** @var \phpbb\attachment\manager $attachment_manager */
        $attachment_manager = $phpbb_container->get('attachment.manager');
        $local_filedata = [
            'realname' => (string) $filename,
            'size'     => strlen($binary),
            'type'     => $mimetype,
        ];

        $filedata = $attachment_manager->upload('', 0, true, $temp, true, $local_filedata);

        if (is_file($temp))
        {
            @unlink($temp);
        }

        return $this->finalize_upload($attachment_manager, $filedata);
    }

    /**
     * @param \phpbb\attachment\manager $attachment_manager
     * @param array<string, mixed> $filedata
     * @return array{success: bool, attach_id?: int, real_filename?: string, preview_url?: string, error?: string}
     */
    protected function finalize_upload($attachment_manager, array $filedata)
    {
        $errors = $this->normalize_upload_errors($filedata['error'] ?? []);
        if (!empty($errors))
        {
            return ['success' => false, 'error' => implode("\n", $errors)];
        }

        if (empty($filedata['post_attach']))
        {
            return ['success' => false, 'error' => $this->language->lang('NOT_UPLOADED')];
        }

        if (!$this->is_image_filedata($filedata))
        {
            if (!empty($filedata['physical_filename']))
            {
                $attachment_manager->unlink($filedata['physical_filename'], 'file');
                if (!empty($filedata['thumbnail']))
                {
                    $attachment_manager->unlink($filedata['thumbnail'], 'thumbnail');
                }
            }

            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_IMAGES_ONLY'];
        }

        $sql_ary = [
            'physical_filename' => (string) $filedata['physical_filename'],
            'attach_comment'    => '',
            'real_filename'     => (string) $filedata['real_filename'],
            'extension'         => (string) $filedata['extension'],
            'mimetype'          => (string) $filedata['mimetype'],
            'filesize'          => (int) $filedata['filesize'],
            'filetime'          => (int) $filedata['filetime'],
            'thumbnail'         => (int) ($filedata['thumbnail'] ?? 0),
            'is_orphan'         => 1,
            'in_message'        => 1,
            'poster_id'         => (int) $this->user->data['user_id'],
        ];

        $this->db->sql_query('INSERT INTO ' . $this->t_attachments . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
        $attach_id = (int) $this->db->sql_nextid();

        if ($attach_id <= 0)
        {
            return ['success' => false, 'error' => 'MESSENGER_UPLOAD_FAILED'];
        }

        return [
            'success'       => true,
            'attach_id'     => $attach_id,
            'real_filename' => (string) $filedata['real_filename'],
            'preview_url'   => $this->get_preview_url($attach_id, (string) $filedata['real_filename']),
        ];
    }

    /**
     * @param int[] $attach_ids
     * @return array<int, array<string, mixed>>
     */
    public function collect_orphan_attachments(array $attach_ids)
    {
        $attach_ids = array_values(array_unique(array_filter(array_map('intval', $attach_ids))));
        if (empty($attach_ids))
        {
            return [];
        }

        $max = (int) $this->config['max_attachments_pm'];
        if ($max > 0 && count($attach_ids) > $max)
        {
            $attach_ids = array_slice($attach_ids, 0, $max);
        }

        $sql = 'SELECT attach_id, is_orphan, real_filename, attach_comment, filesize
                FROM ' . $this->t_attachments . '
                WHERE ' . $this->db->sql_in_set('attach_id', $attach_ids) . '
                    AND poster_id = ' . (int) $this->user->data['user_id'] . '
                    AND is_orphan = 1
                    AND in_message = 1';
        $result = $this->db->sql_query($sql);

        $by_id = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $by_id[(int) $row['attach_id']] = $row;
        }
        $this->db->sql_freeresult($result);

        $attachment_data = [];
        foreach ($attach_ids as $attach_id)
        {
            if (!isset($by_id[$attach_id]))
            {
                continue;
            }

            $row = $by_id[$attach_id];
            $attachment_data[] = [
                'attach_id'      => (int) $row['attach_id'],
                'is_orphan'      => 1,
                'real_filename'  => (string) $row['real_filename'],
                'attach_comment' => (string) ($row['attach_comment'] ?? ''),
                'filesize'       => (int) ($row['filesize'] ?? 0),
            ];
        }

        return $attachment_data;
    }

    /**
     * Ensure uploaded orphans are linked to a sent PM (fallback when phpBB skips linking).
     *
     * @param int[] $attach_ids
     */
    public function link_attachments_to_message($msg_id, array $attach_ids)
    {
        $msg_id = (int) $msg_id;
        $attach_ids = array_values(array_unique(array_filter(array_map('intval', $attach_ids))));
        if ($msg_id <= 0 || empty($attach_ids))
        {
            return;
        }

        $sql = 'UPDATE ' . $this->t_attachments . '
            SET post_msg_id = ' . $msg_id . ',
                is_orphan = 0
            WHERE ' . $this->db->sql_in_set('attach_id', $attach_ids) . '
                AND poster_id = ' . (int) $this->user->data['user_id'] . '
                AND in_message = 1
                AND is_orphan = 1';
        $this->db->sql_query($sql);
    }

    /**
     * @param array<int, array<string, mixed>> $attachment_data
     */
    public function append_attachment_bbcode($message_text, array $attachment_data)
    {
        if (empty($attachment_data))
        {
            return (string) $message_text;
        }

        $lines = [];
        foreach ($attachment_data as $index => $attachment)
        {
            $filename = (string) ($attachment['real_filename'] ?? '');
            if ($filename === '')
            {
                continue;
            }

            $lines[] = '[attachment=' . (int) $index . ']' . $filename . '[/attachment]';
        }

        if (empty($lines))
        {
            return (string) $message_text;
        }

        $message_text = trim((string) $message_text);
        $bbcode = implode("\n", $lines);

        return $message_text === '' ? $bbcode : $message_text . "\n" . $bbcode;
    }

    public function append_attachments_to_html($msg_id, $message_html, $message_text = '', $author_id = 0)
    {
        $msg_id = (int) $msg_id;
        if ($msg_id <= 0)
        {
            return (string) $message_html;
        }

        $attachment_data = $this->get_message_attachment_rows($msg_id);
        if (empty($attachment_data))
        {
            $attachment_data = $this->get_fallback_attachment_rows($msg_id, (string) $message_text, (int) $author_id);
        }

        if (empty($attachment_data))
        {
            return $this->clean_attachment_placeholders((string) $message_html);
        }

        $message_html = $this->clean_attachment_placeholders((string) $message_html);
        $attachments_html = $this->render_message_attachments_html($attachment_data);
        if ($attachments_html === '')
        {
            return $message_html;
        }

        return $message_html === '' ? $attachments_html : $message_html . $attachments_html;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function get_message_attachment_rows($msg_id)
    {
        $msg_id = (int) $msg_id;
        if ($msg_id <= 0)
        {
            return [];
        }

        $sql = 'SELECT attach_id, post_msg_id, topic_id, poster_id, is_orphan, physical_filename,
                       real_filename, download_count, attach_comment, extension, mimetype,
                       filesize, filetime, thumbnail
                FROM ' . $this->t_attachments . '
                WHERE post_msg_id = ' . $msg_id . '
                    AND in_message = 1
                    AND is_orphan = 0
                ORDER BY filetime ASC';
        $result = $this->db->sql_query($sql);

        $attachment_data = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $attachment_data[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $attachment_data;
    }

    /**
     * Recover attachments that were uploaded but never linked to the PM row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function get_fallback_attachment_rows($msg_id, $message_text, $author_id)
    {
        $filenames = $this->extract_attachment_filenames($message_text);
        if (empty($filenames))
        {
            return [];
        }

        $sql = 'SELECT attach_id, post_msg_id, topic_id, poster_id, is_orphan, physical_filename,
                       real_filename, download_count, attach_comment, extension, mimetype,
                       filesize, filetime, thumbnail
                FROM ' . $this->t_attachments . '
                WHERE in_message = 1
                    AND post_msg_id = ' . (int) $msg_id;
        if ($author_id > 0)
        {
            $sql .= ' AND poster_id = ' . (int) $author_id;
        }
        $sql .= ' AND ' . $this->db->sql_in_set('real_filename', $filenames, true, false, true) . '
                ORDER BY filetime ASC';
        $result = $this->db->sql_query($sql);

        $attachment_data = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $attachment_data[] = $row;
        }
        $this->db->sql_freeresult($result);

        if (!empty($attachment_data))
        {
            return $attachment_data;
        }

        $sql = 'SELECT attach_id, post_msg_id, topic_id, poster_id, is_orphan, physical_filename,
                       real_filename, download_count, attach_comment, extension, mimetype,
                       filesize, filetime, thumbnail
                FROM ' . $this->t_attachments . '
                WHERE in_message = 1
                    AND is_orphan = 1
                    AND post_msg_id = 0';
        if ($author_id > 0)
        {
            $sql .= ' AND poster_id = ' . (int) $author_id;
        }
        $sql .= ' AND ' . $this->db->sql_in_set('real_filename', $filenames, true, false, true) . '
                ORDER BY filetime ASC';
        $result = $this->db->sql_query($sql);

        while ($row = $this->db->sql_fetchrow($result))
        {
            $attachment_data[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $attachment_data;
    }

    /**
     * @return string[]
     */
    protected function extract_attachment_filenames($message_text)
    {
        $message_text = (string) $message_text;
        if ($message_text === '' || stripos($message_text, '[attachment=') === false)
        {
            return [];
        }

        if (!preg_match_all('#\[attachment=\d+\](.*?)\[/attachment\]#s', $message_text, $matches))
        {
            return [];
        }

        $filenames = [];
        foreach ($matches[1] as $filename)
        {
            $filename = trim((string) $filename);
            if ($filename !== '')
            {
                $filenames[] = $filename;
            }
        }

        return array_values(array_unique($filenames));
    }

    protected function clean_attachment_placeholders($message_html)
    {
        $message_html = (string) $message_html;
        $message_html = preg_replace('#<!-- ia(\d+) -->.*?<!-- ia\1 -->#s', '', $message_html);
        $message_html = preg_replace('#\[attachment=\d+\][^\[]*\[/attachment\]#s', '', $message_html);
        $message_html = preg_replace('#<div class="(inline-)?attachment[^>]*>\s*</div>#s', '', $message_html);

        return trim($message_html);
    }

    /**
     * @param array<int, array<string, mixed>> $attachment_data
     */
    protected function render_message_attachments_html(array $attachment_data)
    {
        $html = '';

        foreach ($attachment_data as $attachment)
        {
            if (!$this->is_image_attachment_row($attachment))
            {
                continue;
            }

            $attach_id = (int) $attachment['attach_id'];
            $filename = (string) ($attachment['real_filename'] ?? '');
            $view_url = $this->get_attachment_view_url($attach_id, $filename);
            $safe_url = htmlspecialchars($view_url, ENT_QUOTES, 'UTF-8');
            $safe_name = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');

            $html .= '<div class="inline-attachment msgr-inline-image">'
                . '<a href="' . $safe_url . '" class="msgr-image-link" data-msgr-image="' . $safe_url . '">'
                . '<img src="' . $safe_url . '" alt="' . $safe_name . '" loading="lazy">'
                . '</a></div>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function is_image_attachment_row(array $row)
    {
        $mimetype = strtolower((string) ($row['mimetype'] ?? ''));
        if (strpos($mimetype, 'image/') === 0)
        {
            return true;
        }

        $extension = strtolower((string) ($row['extension'] ?? ''));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    protected function get_attachment_view_url($attach_id, $filename = '')
    {
        global $phpbb_root_path, $phpEx;

        $attach_id = (int) $attach_id;

        return append_sid(
            $phpbb_root_path . 'download/file.' . $phpEx,
            'id=' . $attach_id . '&mode=view'
        );
    }

    protected function resolve_upload_field($preferred = 'fileupload')
    {
        foreach (array_unique([$preferred, 'fileupload', 'file']) as $name)
        {
            if ($this->is_valid_upload($this->get_upload_file($name)))
            {
                return $name;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function get_upload_file($name)
    {
        if (!$this->request->is_set($name, \phpbb\request\request_interface::FILES))
        {
            return [];
        }

        $upload = $this->request->raw_variable($name, [], \phpbb\request\request_interface::FILES);

        return is_array($upload) ? $upload : [];
    }

    /**
     * @param array<string, mixed> $upload
     */
    protected function is_valid_upload(array $upload)
    {
        if (empty($upload['tmp_name']) || ($upload['name'] ?? 'none') === 'none')
        {
            return false;
        }

        if (!empty($upload['local_mode']))
        {
            return is_file($upload['tmp_name']);
        }

        return is_uploaded_file($upload['tmp_name']);
    }

    protected function normalize_upload_errors($errors)
    {
        if (!is_array($errors))
        {
            $errors = [$errors];
        }

        $messages = [];
        foreach ($errors as $error)
        {
            $error = trim((string) $error);
            if ($error === '')
            {
                continue;
            }

            $messages[] = $error;
        }

        return $messages;
    }

    protected function format_php_upload_error($code)
    {
        switch ((int) $code)
        {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $this->language->lang('ATTACHMENT_TOO_LARGE');

            case UPLOAD_ERR_PARTIAL:
                return $this->language->lang('PARTIAL_UPLOAD');

            case UPLOAD_ERR_NO_FILE:
                return $this->language->lang('NOT_UPLOADED');

            default:
                return $this->language->lang('NOT_UPLOADED');
        }
    }

    protected function is_image_filedata(array $filedata)
    {
        $mimetype = strtolower((string) ($filedata['mimetype'] ?? ''));
        if (strpos($mimetype, 'image/') === 0)
        {
            return true;
        }

        $extension = strtolower((string) ($filedata['extension'] ?? ''));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    protected function get_preview_url($attach_id, $filename = '')
    {
        return $this->get_attachment_view_url($attach_id, $filename);
    }

    protected function is_upload_storage_ready()
    {
        global $phpbb_root_path;

        $upload_dir = rtrim((string) $phpbb_root_path, '/\\') . '/' . trim((string) $this->config['upload_path'], '/\\');

        return is_dir($upload_dir) && is_writable($upload_dir);
    }

    protected function is_oversized_request()
    {
        $content_length = (int) $this->request->server('CONTENT_LENGTH', 0);
        if ($content_length <= 0)
        {
            return false;
        }

        if ($this->request->is_set('hash', \phpbb\request\request_interface::POST))
        {
            return false;
        }

        if ($this->request->is_set('image_data', \phpbb\request\request_interface::POST))
        {
            return false;
        }

        return true;
    }
}
