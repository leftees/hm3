<?php

class Hm_Handler_imap_summary extends Hm_Handler_Module {
    public function process($data) {
        list($success, $form) = $this->process_form(array('summary_ids'));
        if ($success) {
            $ids = explode(',', $form['summary_ids']);
            foreach($ids as $id) {
                $id = intval($id);
                $cache = Hm_IMAP_List::get_cache($this->session, $id);
                $imap = Hm_IMAP_List::connect($id, $cache);
                if ($imap) {
                    $data['imap_summary'][$id] = $imap->get_mailbox_status('INBOX');
                }
                else {
                    $data['imap_summary'][$id] = array('messages' => '?', 'unseen' => '?');
                }
            }
        }
        return $data;
    }
}

class Hm_Handler_imap_unread extends Hm_Handler_Module {
    public function process($data) {
        list($success, $form) = $this->process_form(array('imap_unread_ids'));
        if ($success) {
            $ids = explode(',', $form['imap_unread_ids']);
            $msg_list = array();
            foreach($ids as $id) {
                $id = intval($id);
                $cache = Hm_IMAP_List::get_cache($this->session, $id);
                $imap = Hm_IMAP_List::connect($id, $cache);
                if ($imap) {
                    $server_details = Hm_IMAP_List::dump($id);
                    if ($imap->select_mailbox('INBOX')) {
                        $unseen = $imap->search('UNSEEN');
                        if ($unseen) {
                            $msgs = $imap->get_message_list($unseen);
                            $imap->bust_cache('ALL');
                            foreach ($msgs as $msg) {
                                $msg['server_id'] = $id;
                                $msg['server_name'] = $server_details['server'];
                                $msg_list[] = $msg;
                            }
                        }
                    }
                }
            }
            usort($msg_list, function($a, $b) {
                if ($a['date'] == $b['date']) return 0;
                return (strtotime($a['date']) > strtotime($b['date']))? -1 : 1;
            });
            $data['imap_unread_data'] = $msg_list;
        }
        return $data;
    }
}

class Hm_Handler_imap_setup extends Hm_Handler_Module {
    public function process($data) {
        if (isset($this->request->post['submit_server'])) {
            list($success, $form) = $this->process_form(array('new_imap_server', 'new_imap_port'));
            if (!$success) {
                $data['old_form'] = $form;
                Hm_Msgs::add('You must supply a server name and port');
            }
            else {
                $tls = false;
                if (isset($this->request->post['tls'])) {
                    $tls = true;
                }
                if ($con = fsockopen($form['new_imap_server'], $form['new_imap_port'], $errno, $errstr, 2)) {
                    Hm_IMAP_List::add( array(
                        'server' => $form['new_imap_server'],
                        'port' => $form['new_imap_port'],
                        'tls' => $tls));
                    Hm_Msgs::add('Added server!');
                }
                else {
                    Hm_Msgs::add(sprintf('Cound not add server: %s', $errstr));
                }
            }
        }
        return $data;
    }
}

class Hm_Handler_save_imap_cache extends Hm_Handler_Module {
    public function process($data) {
        $cache = $this->session->get('imap_cache', array());
        $servers = Hm_IMAP_List::dump(false, true);
        foreach ($servers as $index => $server) {
            if (isset($server['object']) && is_object($server['object'])) {
                $cache[$index] = $server['object']->dump_cache('gzip');
            }
        }
        if (count($cache) > 0) {
            $this->session->set('imap_cache', $cache);
            Hm_Debug::add(sprintf('Cached data for %d IMAP connections', count($cache)));
        }
        return $data;
    }
}

class Hm_Handler_save_imap_servers extends Hm_Handler_Module {
    public function process($data) {
        $servers = Hm_IMAP_List::dump();
        $cache = $this->session->get('imap_cache', array());
        $new_cache = array();
        foreach ($cache as $index => $cache_str) {
            if (isset($servers[$index])) {
                $new_cache[$index] = $cache_str;
            }
        }
        $this->user_config->set('imap_servers', $servers);
        $this->session->set('imap_cache', $new_cache);
        Hm_IMAP_List::clean_up();
        return $data;
    }
}

class Hm_Handler_load_imap_servers extends Hm_Handler_Module {
    public function process($data) {
        $servers = $this->user_config->get('imap_servers', array());
        foreach ($servers as $index => $server) {
            Hm_IMAP_List::add( $server, $index );
        }
        return $data;
    }
}

class Hm_Handler_imap_setup_display extends Hm_Handler_Module {
    public function process($data) {
        $data['imap_servers'] = array();
        $servers = Hm_IMAP_List::dump();
        if (!empty($servers)) {
            $data['imap_servers'] = $servers;
        }
        return $data;
    }
}

class Hm_Handler_imap_connect extends Hm_Handler_Module {
    public function process($data) {
        $data['just_saved_credentials'] = false;
        $data['just_forgot_credentials'] = false;
        $remember = false;
        $remembered = false;
        if (isset($this->request->post['imap_remember'])) {
            $remember = true;
        }
        if (isset($this->request->post['imap_connect'])) {
            list($success, $form) = $this->process_form(array('imap_user', 'imap_pass', 'imap_server_id'));
            $imap = false;
            $cache = Hm_IMAP_List::get_cache($this->session, $form['imap_server_id']);
            if ($success) {
                $imap = Hm_IMAP_List::connect($form['imap_server_id'], $cache, $form['imap_user'], $form['imap_pass'], $remember);
            }
            elseif (isset($form['imap_server_id'])) {
                $imap = Hm_IMAP_List::connect($form['imap_server_id'], $cache);
                $remembered = true;
            }
            if ($imap) {
                if ($remember) {
                    $data['just_saved_credentials'] = true;
                }
                if (!$remember && $remembered) {
                    Hm_IMAP_List::forget_credentials($form['imap_server_id']);
                    $data['just_forgot_credentials'] = true;
                }
                if ($imap->get_state() == 'authenticated') {
                    Hm_Msgs::add("Successfully authenticated to the IMAP server");
                    $data['imap_folders'] = $imap->get_folder_list_by_level();
                }
                else {
                    Hm_Msgs::add("Failed to authenticate to the IMAP server");
                }
                $data['imap_debug'] = $imap->show_debug(false, true);
            }
            else {
                Hm_Msgs::add('Username and password are required');
                $data['old_form'] = $form;
            }
        }
        return $data;
    }
}

class Hm_Handler_imap_delete extends Hm_Handler_Module {
    public function process($data) {
        if (isset($this->request->post['imap_delete'])) {
            list($success, $form) = $this->process_form(array('imap_server_id'));
            if ($success) {
                $res = Hm_IMAP_List::del($form['imap_server_id']);
                if ($res) {
                    $data['deleted_server_id'] = $form['imap_server_id'];
                    Hm_Msgs::add('Server deleted');
                }
            }
            else {
                $data['old_form'] = $form;
            }
        }
        return $data;
    }
}

/* wrapper around multiple imap connections */
class Hm_IMAP_List {

    private static $imap_list = array();

    public static function connect($id, $cache=false, $user=false, $pass=false, $save_credentials=false) {
        if (isset(self::$imap_list[$id])) {
            $imap = self::$imap_list[$id];
            if ($imap['object']) {
                return $imap['object'];
            }
            else {
                if ((!$user || !$pass) && (!isset($imap['user']) || !isset($imap['pass']))) {
                    return false;
                }
                elseif (isset($imap['user']) && isset($imap['pass'])) {
                    $user = $imap['user'];
                    $pass = $imap['pass'];
                }
                if ($user && $pass) {
                    self::$imap_list[$id]['object'] = new Hm_IMAP();
                    if ($cache) {
                        self::$imap_list[$id]['object']->load_cache($cache, 'gzip');
                    }
                    $res = self::$imap_list[$id]['object']->connect(array(
                        'server' => $imap['server'],
                        'port' => $imap['port'],
                        'tls' => $imap['tls'],
                        'username' => $user,
                        'password' => $pass
                    ));
                    if ($res) {
                        self::$imap_list[$id]['connected'] = true;
                        if ($save_credentials) {
                            self::$imap_list[$id]['user'] = $user;
                            self::$imap_list[$id]['pass'] = $pass;
                        }
                    }
                    return self::$imap_list[$id]['object'];
                }
            }
        }
        return false;
    }

    public static function forget_credentials($id) {
        if (isset(self::$imap_list[$id])) {
            unset(self::$imap_list[$id]['user']);
            unset(self::$imap_list[$id]['pass']);
        }
    }

    public static function add($atts, $id=false) {
        $atts['object'] = false;
        $atts['connected'] = false;
        if ($id) {
            self::$imap_list[$id] = $atts;
        }
        else {
            self::$imap_list[] = $atts;
        }
    }

    public static function del($id) {
        if (isset(self::$imap_list[$id])) {
            unset(self::$imap_list[$id]);
            return true;
        }
        return false;
    }

    public static function dump($id=false, $full=false) {
        $list = array();
        foreach (self::$imap_list as $index => $server) {
            if ($id !== false && $index != $id) {
                continue;
            }
            if ($full) {
                $list[$index] = $server;
            }
            else {
                $list[$index] = array(
                    'server' => $server['server'],
                    'port' => $server['port'],
                    'tls' => $server['tls']
                );
                if (isset($server['user'])) {
                    $list[$index]['user'] = $server['user'];
                }
                if (isset($server['pass'])) {
                    $list[$index]['pass'] = $server['pass'];
                }
            }
            if ($id !== false) {
                return $list[$index];
            }
        }
        return $list;
    }

    public static function clean_up($id=false) {
        foreach (self::$imap_list as $index => $server) {
            if ($id !== false && $id != $index) {
                continue;
            }
            if ($server['connected'] && $server['object']) {
                self::$imap_list[$index]['object']->disconnect();
                self::$imap_list[$index]['connected'] = false;
            }
        }
    }
    public static function get_cache($session, $id) {
        $imap_cache = $session->get('imap_cache', array());
        if (isset($imap_cache[$id])) {
            return $imap_cache[$id];
        }
        return false;
    }
}

class Hm_Output_imap_setup_display extends Hm_Output_Module {
    protected function output($input, $format) {
        $res = '';
        if ($format == 'HTML5') {
            $res = '<div class="configured_servers"><div class="subtitle">Configured IMAP Servers</div>';
            foreach ($input['imap_servers'] as $index => $vals) {

                if (isset($vals['user'])) {
                    $disabled = 'disabled="disabled"';
                    $display = 'none';
                }
                else {
                    $disabled = '';
                    $display = 'inline';
                }
                $res .= '<div class="configured_server">';
                $res .= sprintf("Server: %s<br />Port: %d<br />TLS: %s<br /><br />", $this->html_safe($vals['server']),
                    $this->html_safe($vals['port']), $vals['tls'] ? 'true' : 'false' );
                $res .= 
                    ' <form class="imap_connect" method="POST">'.
                    '<input type="hidden" name="imap_server_id" value="'.$this->html_safe($index).'" />'.
                    '<span style="display: '.$display.'"> '.$this->trans('Username').': '.
                    '<input '.$disabled.' class="credentials" type="text" name="imap_user" value=""></span>'.
                    '<span style="display: '.$display.'"> '.$this->trans('Password').': '.
                    '<input '.$disabled.' class="credentials" type="password" name="imap_pass"></span>'.
                    ' Remember: <input type="checkbox" '. (isset($vals['user']) ? 'checked="checked" ' : '').
                    ' value="1" name="imap_remember" /><br /><br />'.
                    ' <input type="submit" value="Test Connection" class="test_connect" />'.
                    ' <input type="submit" value="Delete" class="imap_delete" />'.
                    ' <input type="hidden" value="ajax_imap_debug" name="hm_ajax_hook" />'.
                    '</form></div>';
            }
            $res .= '</div>';
        }
        return $res;
    }
}

class Hm_Output_imap_setup extends Hm_Output_Module {
    protected function output($input, $format) {
        if ($format == 'HTML5') {
            return '<div class="subtitle">Add an IMAP server</div>'.
                '<form class="add_server" method="POST">'.
                'Server name or address: <input type="text" name="new_imap_server" value=""/><br />'.
                'Server Port: <input type="text" name="new_imap_port" value="143"><br />'.
                'Use TLS: <input type="checkbox" name="tls" value="1" /><br />'.
                '<input type="submit" value="Add" onclick="$( this ).css(\'visibility\', \'hidden\'); return true;" name="submit_server" /></form>';
        }
    }
}

class Hm_Output_servers_link extends Hm_Output_Module {
    protected function output($input, $format, $lang_str=false) {
        if ($format == 'HTML5') {
            return '<a class="server_link" href="'.$this->html_safe($input['router_url_path']).'?page=servers">Servers</a>';
        }
    }
}

class Hm_Output_unread_link extends Hm_Output_Module {
    protected function output($input, $format, $lang_str=false) {
        if ($format == 'HTML5') {
            return '<a class="unread_link" href="'.$this->html_safe($input['router_url_path']).'?page=unread">Unread</a>';
        }
    }
}

class Hm_Output_homepage_link extends Hm_Output_Module {
    protected function output($input, $format, $lang_str=false) {
        if ($format == 'HTML5') {
            return '<a class="home_link" href="'.$this->html_safe($input['router_url_path']).'">Home</a>';
        }
    }
}

class Hm_Output_imap_summary extends Hm_Output_Module {
    protected function output($input, $format, $lang_str=false) {
        if ($format == 'HTML5') {
            $res = '<div class="imap_summary"><div class="subtitle">IMAP Summary</div>';
            if (isset($input['imap_servers']) && !empty($input['imap_servers'])) {
                $res .= '<input type="hidden" id="imap_summary_ids" value="'.
                    $this->html_safe(implode(',', array_keys($input['imap_servers']))).'" />';
                $res .= '<div class="imap_summary_data">';
                foreach ($input['imap_servers'] as $index => $vals) {
                    $res .= '<div class="server_label">'.$vals['server'].'/'.$vals['port'].
                        ($vals['tls'] ? '/TLS' : '').
                        '</div><div class="imap_summary_row imap_summary_'.$index.'">'.
                        '<table><tr><td>INBOX</td></tr>'.
                        '<tr><td>Unseen</td><td><span class="unseen">...</span></td></tr>'.
                        '<tr><td>Total</td><td><span class="total">...</span></td></tr>'.
                        '</table></div>';
                }
                $res .= '</div>';
            }
            else {
                $res .= '<div>'.$this->trans('No IMAP servers found!').' '.
                    '<a href="'.$this->html_safe($input['router_url_path']).
                    '?page=servers">'.$this->trans('Add some').'</a></div>';
            }
            $res .= '</div>';
            return $res;
        }
    }
}
class Hm_Output_unread_message_list extends Hm_Output_Module {
    protected function output($input, $format, $lang_str=false) {
        if ($format == 'HTML5') {
            $res = '';
            if (isset($input['imap_servers'])) {
                $res .= '<input type="hidden" id="imap_unread_ids" value="'.$this->html_safe(implode(',', array_keys($input['imap_servers']))).'" />';
            }
            $res .= '<div class="subtitle">Unread Messages</div><div class="unread_messages"></div>';
            return $res;
        }
    }
}

class Hm_Output_filter_unread_data extends Hm_Output_Module {
    protected function output($input, $format, $lang_str=false) {
        $clean = array();
        foreach($input['imap_unread_data'] as $msg) {
            $clean[] = array_map(function($v) { return $this->html_safe($v); }, $msg);
        }
        $input['imap_unread_data'] = $clean;
        return $input;
    }
}
?>