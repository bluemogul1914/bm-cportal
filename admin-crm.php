<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$is_admin = true;
$pdo = getDB();

$success_msg = '';
$error_msg = '';
$tab = $_GET['tab'] ?? 'leads';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_lead') {
        $name = trim($_POST['lead_name'] ?? '');
        $email = trim($_POST['lead_email'] ?? '');
        $phone = trim($_POST['lead_phone'] ?? '');
        $company = trim($_POST['lead_company'] ?? '');
        $source = $_POST['lead_source'] ?? 'manual';
        $notes = trim($_POST['lead_notes'] ?? '');
        $industry = trim($_POST['lead_industry'] ?? '');
        $employee_count = trim($_POST['lead_employee_count'] ?? '');
        $service_interest = trim($_POST['lead_service_interest'] ?? '');
        $geography = trim($_POST['lead_geography'] ?? '');
        $lead_score = intval($_POST['lead_score'] ?? 0);
        $next_action_date = $_POST['lead_next_action_date'] ?? null;
        if ($name) {
            $pdo->prepare("INSERT INTO crm_leads (name, email, phone, company, source, notes, industry, employee_count, service_interest, geography, lead_score, next_action_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$name, $email, $phone, $company, $source, $notes, $industry ?: null, $employee_count ?: null, $service_interest ?: null, $geography ?: null, $lead_score, $next_action_date ?: null]);
            $success_msg = "Lead '$name' added successfully.";
        } else {
            $error_msg = 'Lead name is required.';
        }
        $tab = 'leads';
    }

    if ($action === 'add_company') {
        $cname = trim($_POST['company_name'] ?? '');
        $phones_json  = $_POST['co_phones_json']  ?? '[]';
        $emails_json  = $_POST['co_emails_json']  ?? '[]';
        $socials_json = $_POST['co_socials_json'] ?? '[]';
        $phones_arr  = json_decode($phones_json,  true) ?: [];
        $emails_arr  = json_decode($emails_json,  true) ?: [];
        $socials_arr = json_decode($socials_json, true) ?: [];
        $phone   = $phones_arr[0]['value'] ?? '';
        $email   = $emails_arr[0]['value'] ?? '';
        $website = '';
        $linkedin_url = '';
        foreach ($socials_arr as $s) {
            if (strtolower($s['type'] ?? '') === 'linkedin')      { $linkedin_url = $s['value'] ?? ''; }
            elseif (strtolower($s['type'] ?? '') === 'website')   { $website = $s['value'] ?? ''; }
        }
        $city = trim($_POST['company_city'] ?? '');
        $state = trim($_POST['company_state'] ?? '');
        $country = trim($_POST['company_country'] ?? 'United States');
        $address = trim($_POST['company_address'] ?? '');
        $postal_code = trim($_POST['company_postal_code'] ?? '');
        $industry = trim($_POST['company_industry'] ?? '');
        $employee_count = trim($_POST['company_employee_count'] ?? '');
        $owner = trim($_POST['company_owner'] ?? '');
        $lifecycle = $_POST['company_lifecycle'] ?? 'lead';
        $notes = trim($_POST['company_notes'] ?? '');
        $tags_raw = trim($_POST['company_tags'] ?? '');
        $tags_arr = array_filter(array_map('trim', explode(',', $tags_raw)));
        $tags_pg  = empty($tags_arr) ? null : '{' . implode(',', array_map(fn($t) => '"' . str_replace('"', '\\"', $t) . '"', $tags_arr)) . '}';
        if ($cname) {
            $pdo->prepare("INSERT INTO crm_companies (name, phone, email, website, linkedin_url, phones, emails, social_links, tags, city, state, country, address, postal_code, industry, employee_count, company_owner, lifecycle_stage, notes, created_by) VALUES (?,?,?,?,?,?::jsonb,?::jsonb,?::jsonb,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$cname, $phone ?: null, $email ?: null, $website ?: null, $linkedin_url ?: null, $phones_json, $emails_json, $socials_json, $tags_pg, $city ?: null, $state ?: null, $country, $address ?: null, $postal_code ?: null, $industry ?: null, $employee_count ?: null, $owner ?: null, $lifecycle, $notes ?: null, $_SESSION['user_id']]);
            $success_msg = "Company '$cname' added.";
        } else { $error_msg = 'Company name is required.'; }
        $tab = 'companies';
    }

    if ($action === 'delete_company') {
        $cid = intval($_POST['company_id'] ?? 0);
        if ($cid) { $pdo->prepare("DELETE FROM crm_companies WHERE id = ?")->execute([$cid]); $success_msg = 'Company deleted.'; }
        $tab = 'companies';
    }

    if ($action === 'update_company_lifecycle') {
        $cid = intval($_POST['company_id'] ?? 0);
        $stage = $_POST['lifecycle_stage'] ?? 'lead';
        if ($cid) { $pdo->prepare("UPDATE crm_companies SET lifecycle_stage = ?, updated_at = NOW() WHERE id = ?")->execute([$stage, $cid]); }
        $tab = 'companies';
    }

    if ($action === 'add_deal') {
        $title = trim($_POST['deal_title'] ?? '');
        $lead_id = intval($_POST['deal_lead_id'] ?? 0) ?: null;
        $client_id = intval($_POST['deal_client_id'] ?? 0) ?: null;
        $stage = $_POST['deal_stage'] ?? 'prospecting';
        $value = floatval($_POST['deal_value'] ?? 0);
        $close_date = $_POST['deal_close_date'] ?? null;
        $notes = trim($_POST['deal_notes'] ?? '');
        if ($title) {
            try {
                $pdo->prepare("INSERT INTO crm_deals (title, lead_id, client_id, stage, value, expected_close_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$title, $lead_id, $client_id, $stage, $value, $close_date ?: null, $notes, $_SESSION['user_id']]);
                $success_msg = "Deal '$title' created.";
            } catch (Exception $e) { $error_msg = 'Failed to create deal: ' . $e->getMessage(); }
        } else { $error_msg = 'Deal title is required.'; }
        $tab = 'deals';
    }

    if ($action === 'update_deal_stage') {
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $stage = $_POST['stage'] ?? 'prospecting';
        if ($deal_id) {
            try {
                $pdo->prepare("UPDATE crm_deals SET stage = ?, updated_at = NOW() WHERE id = ?")->execute([$stage, $deal_id]);
            } catch (Exception $e) { $error_msg = 'Failed to update deal stage.'; }
        }
        $tab = 'deals';
    }

    if ($action === 'delete_deal') {
        $deal_id = intval($_POST['deal_id'] ?? 0);
        if ($deal_id) {
            try { $pdo->prepare("DELETE FROM crm_deals WHERE id = ?")->execute([$deal_id]); }
            catch (Exception $e) { $error_msg = 'Failed to delete deal.'; }
        }
        $tab = 'deals';
    }

    if ($action === 'save_social_api') {
        $platforms = ['facebook','youtube','instagram','linkedin'];
        foreach ($platforms as $plat) {
            $fields = ['app_id','app_secret','access_token','page_id','channel_id','org_id','api_key'];
            foreach ($fields as $f) {
                $key = "social_{$plat}_{$f}";
                if (isset($_POST[$key])) {
                    $val = trim($_POST[$key]);
                    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?,?,NOW()) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value, updated_at=NOW()")
                        ->execute([$key, $val ?: null]);
                }
            }
        }
        $success_msg = 'API settings saved.';
        $tab = 'marketing';
    }

    if ($action === 'add_company_comm') {
        $cid = intval($_POST['comm_company_id'] ?? 0);
        $type = $_POST['comm_type'] ?? 'note';
        $subject = trim($_POST['comm_subject'] ?? '');
        $body = trim($_POST['comm_body'] ?? '');
        $direction = $_POST['comm_direction'] ?? 'outbound';
        $duration = intval($_POST['comm_duration'] ?? 0) ?: null;
        $outcome = trim($_POST['comm_outcome'] ?? '');
        $scheduled_at = trim($_POST['comm_scheduled_at'] ?? '') ?: null;
        if ($cid && $body) {
            $pdo->prepare("INSERT INTO crm_communications (entity_type, entity_id, type, subject, body, direction, duration_minutes, outcome, scheduled_at, created_by) VALUES ('company',?,?,?,?,?,?,?,?,?)")
                ->execute([$cid, $type, $subject ?: null, $body, $direction, $duration, $outcome ?: null, $scheduled_at, $_SESSION['user_id']]);
            $success_msg = ucfirst($type) . ' logged.';
        }
        $tab = 'companies';
        header("Location: ?tab=companies&cid=$cid&cv=activities");
        exit;
    }

    if ($action === 'delete_company_comm') {
        $comm_id = intval($_POST['comm_id'] ?? 0);
        $cid = intval($_POST['comm_company_id'] ?? 0);
        if ($comm_id) { $pdo->prepare("DELETE FROM crm_communications WHERE id = ? AND entity_type = 'company'")->execute([$comm_id]); }
        $tab = 'companies';
        header("Location: ?tab=companies&cid=$cid&cv=activities");
        exit;
    }

    if ($action === 'add_lead_comm') {
        $lid = intval($_POST['comm_lead_id'] ?? 0);
        $type = $_POST['comm_type'] ?? 'note';
        $subject = trim($_POST['comm_subject'] ?? '');
        $body = trim($_POST['comm_body'] ?? '');
        $direction = $_POST['comm_direction'] ?? 'outbound';
        $duration = intval($_POST['comm_duration'] ?? 0) ?: null;
        $outcome = trim($_POST['comm_outcome'] ?? '');
        $scheduled_at = trim($_POST['comm_scheduled_at'] ?? '') ?: null;
        if ($lid && $body) {
            $pdo->prepare("INSERT INTO crm_communications (entity_type, entity_id, type, subject, body, direction, duration_minutes, outcome, scheduled_at, created_by) VALUES ('lead',?,?,?,?,?,?,?,?,?)")
                ->execute([$lid, $type, $subject ?: null, $body, $direction, $duration, $outcome ?: null, $scheduled_at, $_SESSION['user_id']]);
            // if email, try to send
            if ($type === 'email') {
                foreach ($leads as $ll) {
                    if ($ll['id'] == $lid && !empty($ll['email'])) {
                        try { send_email($ll['email'], $subject ?: 'Message from Blue Mogul', nl2br(htmlspecialchars($body))); } catch(\Exception $e) {}
                        break;
                    }
                }
            }
        }
        header("Location: ?tab=leads&lid=$lid&lv=activities");
        exit;
    }

    if ($action === 'delete_lead_comm') {
        $comm_id = intval($_POST['comm_id'] ?? 0);
        $lid = intval($_POST['comm_lead_id'] ?? 0);
        if ($comm_id) { $pdo->prepare("DELETE FROM crm_communications WHERE id = ? AND entity_type = 'lead'")->execute([$comm_id]); }
        header("Location: ?tab=leads&lid=$lid&lv=activities");
        exit;
    }

    if ($action === 'post_to_platform') {
        $platform  = $_POST['post_platform'] ?? '';
        $content   = trim($_POST['post_content'] ?? '');
        $image_url = trim($_POST['post_image_url'] ?? '');
        $post_result = '';
        if ($platform && $content) {
            $sapi = [];
            try {
                $rows2 = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'social_%'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows2 as $r) { $sapi[$r['setting_key']] = $r['setting_value']; }
            } catch(\Exception $e) {}

            if ($platform === 'facebook') {
                $token   = $sapi['social_facebook_access_token'] ?? '';
                $page_id = $sapi['social_facebook_page_id'] ?? '';
                if ($token && $page_id) {
                    $params = ['message' => $content, 'access_token' => $token];
                    if ($image_url) $params['link'] = $image_url;
                    $ch = curl_init("https://graph.facebook.com/v18.0/$page_id/feed");
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params)]);
                    $r2 = json_decode(curl_exec($ch), true); curl_close($ch);
                    $success_msg = !empty($r2['id']) ? 'Posted to Facebook! Post ID: '.$r2['id'] : 'Facebook error: '.($r2['error']['message'] ?? 'Unknown');
                } else { $error_msg = 'Facebook credentials not configured.'; }

            } elseif ($platform === 'instagram') {
                $token   = $sapi['social_instagram_access_token'] ?? '';
                $iguid   = $sapi['social_instagram_page_id'] ?? '';
                if ($token && $iguid && $image_url) {
                    $ch = curl_init("https://graph.facebook.com/v18.0/$iguid/media");
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['image_url'=>$image_url,'caption'=>$content,'access_token'=>$token])]);
                    $r2 = json_decode(curl_exec($ch), true); curl_close($ch);
                    if (!empty($r2['id'])) {
                        $ch2 = curl_init("https://graph.facebook.com/v18.0/$iguid/media_publish");
                        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['creation_id'=>$r2['id'],'access_token'=>$token])]);
                        $r3 = json_decode(curl_exec($ch2), true); curl_close($ch2);
                        $success_msg = !empty($r3['id']) ? 'Posted to Instagram! Media ID: '.$r3['id'] : 'Instagram publish error: '.($r3['error']['message'] ?? 'Unknown');
                    } else { $error_msg = 'Instagram media create error: '.($r2['error']['message'] ?? 'Unknown'); }
                } elseif (!$image_url) { $error_msg = 'Instagram requires an image URL.';
                } else { $error_msg = 'Instagram credentials not configured.'; }

            } elseif ($platform === 'linkedin') {
                $token   = $sapi['social_linkedin_access_token'] ?? '';
                $org_id  = $sapi['social_linkedin_org_id'] ?? '';
                if ($token && $org_id) {
                    $body_data = json_encode(['author'=>"urn:li:organization:$org_id",'lifecycleState'=>'PUBLISHED','specificContent'=>['com.linkedin.ugc.ShareContent'=>['shareCommentary'=>['text'=>$content],'shareMediaCategory'=>'NONE']],'visibility'=>['com.linkedin.ugc.MemberNetworkVisibility'=>'PUBLIC']]);
                    $ch = curl_init('https://api.linkedin.com/v2/ugcPosts');
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body_data,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json','X-Restli-Protocol-Version: 2.0.0']]);
                    $r2 = json_decode(curl_exec($ch), true); curl_close($ch);
                    $success_msg = !empty($r2['id']) ? 'Posted to LinkedIn!' : 'LinkedIn error: '.($r2['message'] ?? 'Unknown');
                } else { $error_msg = 'LinkedIn credentials not configured.'; }

            } elseif ($platform === 'youtube') {
                $error_msg = 'YouTube video uploads require the YouTube Data API v3 upload flow. Please use YouTube Studio to post videos.';
            }
            // save to post log
            if (!$error_msg) {
                try { $pdo->prepare("INSERT INTO crm_social_posts (platform, content, scheduled_at, status, created_by) VALUES (?,?,NOW(),'published',?)")->execute([$platform, $content, $_SESSION['user_id']]); } catch(\Exception $e) {}
            }
        }
        $tab = 'marketing';
    }

    if ($action === 'add_social_post') {
        $platform = $_POST['post_platform'] ?? 'facebook';
        $content = trim($_POST['post_content'] ?? '');
        $scheduled_at = $_POST['post_scheduled_at'] ?? null;
        if ($content) {
            $pdo->prepare("INSERT INTO crm_social_posts (platform, content, scheduled_at, status, created_by) VALUES (?, ?, ?, 'scheduled', ?)")
                ->execute([$platform, $content, $scheduled_at ?: null, $_SESSION['user_id']]);
            $success_msg = 'Social post scheduled.';
        }
        $tab = 'marketing';
    }

    if ($action === 'delete_social_post') {
        $post_id = intval($_POST['post_id'] ?? 0);
        if ($post_id) { $pdo->prepare("DELETE FROM crm_social_posts WHERE id = ?")->execute([$post_id]); }
        $tab = 'marketing';
    }

    if ($action === 'update_lead_status') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $status = $_POST['status'] ?? 'new';
        if ($lead_id) {
            $pdo->prepare("UPDATE crm_leads SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $lead_id]);
            $success_msg = 'Lead status updated.';
        }
        $tab = 'leads';
    }

    if ($action === 'delete_lead') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        if ($lead_id) {
            $pdo->prepare("DELETE FROM crm_leads WHERE id = ?")->execute([$lead_id]);
            $success_msg = 'Lead deleted.';
        }
        $tab = 'leads';
    }

    if ($action === 'convert_lead') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        if ($lead_id) {
            $lead = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ?");
            $lead->execute([$lead_id]);
            $lead = $lead->fetch(PDO::FETCH_ASSOC);
            if ($lead) {
                $temp_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO users (email, password, name, is_admin, role, status, created_at) VALUES (?, ?, ?, false, 'client', 'active', NOW()) ON CONFLICT (email) DO NOTHING")
                    ->execute([$lead['email'] ?: $lead['name'] . '@pending.local', $temp_pass, $lead['name']]);
                $user = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $user->execute([$lead['email'] ?: $lead['name'] . '@pending.local']);
                $user = $user->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $pdo->prepare("INSERT INTO clients (user_id, name, email, phone, company, status) VALUES (?, ?, ?, ?, ?, 'active') ON CONFLICT DO NOTHING")
                        ->execute([$user['id'], $lead['name'], $lead['email'], $lead['phone'], $lead['company']]);
                }
                $pdo->prepare("UPDATE crm_leads SET status = 'won', updated_at = NOW() WHERE id = ?")->execute([$lead_id]);
                $success_msg = "Lead '{$lead['name']}' converted to client.";
            }
        }
        $tab = 'leads';
    }

    if ($action === 'add_campaign') {
        $name = trim($_POST['campaign_name'] ?? '');
        $type = $_POST['campaign_type'] ?? 'email';
        $subject = trim($_POST['campaign_subject'] ?? '');
        $content = trim($_POST['campaign_content'] ?? '');
        $target = $_POST['campaign_target'] ?? 'all_clients';
        $start = $_POST['campaign_start'] ?? null;
        $end = $_POST['campaign_end'] ?? null;
        if ($name) {
            $pdo->prepare("INSERT INTO crm_campaigns (name, type, subject, content, target_audience, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$name, $type, $subject, $content, $target, $start ?: null, $end ?: null, $_SESSION['user_id']]);
            $success_msg = "Campaign '$name' created.";
        } else {
            $error_msg = 'Campaign name is required.';
        }
        $tab = 'campaigns';
    }

    if ($action === 'update_campaign_status') {
        $campaign_id = (int)($_POST['campaign_id'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        if ($campaign_id) {
            $pdo->prepare("UPDATE crm_campaigns SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $campaign_id]);
            $success_msg = 'Campaign status updated.';
        }
        $tab = 'campaigns';
    }

    if ($action === 'delete_campaign') {
        $campaign_id = (int)($_POST['campaign_id'] ?? 0);
        if ($campaign_id) {
            $pdo->prepare("DELETE FROM crm_campaigns WHERE id = ?")->execute([$campaign_id]);
            $success_msg = 'Campaign deleted.';
        }
        $tab = 'campaigns';
    }

    if ($action === 'add_meeting') {
        $title = trim($_POST['meeting_title'] ?? '');
        $client_id = (int)($_POST['meeting_client_id'] ?? 0);
        $client_name = trim($_POST['meeting_client_name'] ?? '');
        $type = $_POST['meeting_type'] ?? 'consultation';
        $date = $_POST['meeting_date'] ?? '';
        $time = $_POST['meeting_time'] ?? '09:00';
        $duration = (int)($_POST['meeting_duration'] ?? 60);
        $location = trim($_POST['meeting_location'] ?? '');
        $notes = trim($_POST['meeting_notes'] ?? '');
        $calendar_link = trim($_POST['meeting_calendar_link'] ?? '');
        if ($title && $date) {
            $scheduled = $date . ' ' . $time . ':00';
            if ($client_id && !$client_name) {
                $c = $pdo->prepare("SELECT name, company FROM clients WHERE id = ?");
                $c->execute([$client_id]);
                $c = $c->fetch(PDO::FETCH_ASSOC);
                $client_name = $c ? ($c['company'] ?: $c['name']) : '';
            }
            try {
                $pdo->prepare("INSERT INTO crm_meetings (title, client_id, client_name, meeting_type, scheduled_at, duration_minutes, location, notes, calendar_link, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$title, $client_id ?: null, $client_name, $type, $scheduled, $duration, $location, $notes, $calendar_link ?: null, $_SESSION['user_id']]);
            } catch (PDOException $e) {
                $pdo->prepare("INSERT INTO crm_meetings (title, client_id, client_name, meeting_type, scheduled_at, duration_minutes, location, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$title, $client_id ?: null, $client_name, $type, $scheduled, $duration, $location, $notes, $_SESSION['user_id']]);
            }
            $success_msg = "Meeting '$title' scheduled.";
        } else {
            $error_msg = 'Meeting title and date are required.';
        }
        $tab = 'meetings';
    }

    if ($action === 'update_meeting_status') {
        $meeting_id = (int)($_POST['meeting_id'] ?? 0);
        $status = $_POST['status'] ?? 'scheduled';
        if ($meeting_id) {
            $pdo->prepare("UPDATE crm_meetings SET status = ? WHERE id = ?")->execute([$status, $meeting_id]);
            $success_msg = 'Meeting status updated.';
        }
        $tab = 'meetings';
    }

    if ($action === 'delete_meeting') {
        $meeting_id = (int)($_POST['meeting_id'] ?? 0);
        if ($meeting_id) {
            $pdo->prepare("DELETE FROM crm_meetings WHERE id = ?")->execute([$meeting_id]);
            $success_msg = 'Meeting deleted.';
        }
        $tab = 'meetings';
    }

    if ($action === 'sync_hubspot') {
        $tab = 'leads';
        $hs_token = defined('HUBSPOT_TOKEN') ? HUBSPOT_TOKEN : '';
        if (empty($hs_token)) {
            $error_msg = 'HubSpot API token is not configured. Set HUBSPOT_TOKEN in environment.';
        } else {
            $synced = 0; $hs_errors = 0;
            $hs_url = 'https://api.hubapi.com/crm/v3/objects/contacts?limit=100&properties=firstname,lastname,email,phone,company,hs_lead_status,lifecyclestage,createdate';
            $has_more = true;
            $after = '';
            while ($has_more) {
                $fetch_url = $hs_url . ($after ? '&after=' . $after : '');
                $ctx = stream_context_create(['http' => [
                    'method' => 'GET',
                    'header' => "Authorization: Bearer $hs_token\r\nContent-Type: application/json\r\n",
                    'timeout' => 15,
                    'ignore_errors' => true
                ]]);
                $resp = @file_get_contents($fetch_url, false, $ctx);
                if (!$resp) { $error_msg = 'Failed to connect to HubSpot API.'; break; }
                $data = json_decode($resp, true);
                if (isset($data['status']) && $data['status'] === 'error') {
                    $error_msg = 'HubSpot API error: ' . ($data['message'] ?? 'Unknown error');
                    break;
                }
                $contacts = $data['results'] ?? [];
                foreach ($contacts as $c) {
                    $props = $c['properties'] ?? [];
                    $first = trim($props['firstname'] ?? '');
                    $last = trim($props['lastname'] ?? '');
                    $name = trim("$first $last");
                    if (!$name) { $hs_errors++; continue; }
                    $email = trim($props['email'] ?? '');
                    $phone = trim($props['phone'] ?? '');
                    $company = trim($props['company'] ?? '');
                    $hs_status = strtolower(trim($props['hs_lead_status'] ?? ''));
                    $status_map = [
                        'new' => 'new', 'open' => 'new', 'in_progress' => 'contacted',
                        'open_deal' => 'qualified', 'unqualified' => 'lost',
                        'attempted_to_contact' => 'contacted', 'connected' => 'contacted',
                        'bad_timing' => 'lost',
                    ];
                    $local_status = $status_map[$hs_status] ?? 'new';

                    try {
                        $existing = null;
                        if ($email) {
                            $dup = $pdo->prepare("SELECT id FROM crm_leads WHERE email = ?");
                            $dup->execute([$email]);
                            $existing = $dup->fetch(PDO::FETCH_ASSOC);
                        }
                        if (!$existing && $name) {
                            $dup = $pdo->prepare("SELECT id FROM crm_leads WHERE name = ? AND source = 'hubspot'");
                            $dup->execute([$name]);
                            $existing = $dup->fetch(PDO::FETCH_ASSOC);
                        }
                        if ($existing) {
                            $pdo->prepare("UPDATE crm_leads SET name = ?, phone = ?, company = ?, source = 'hubspot', status = ?, updated_at = NOW() WHERE id = ?")
                                ->execute([$name, $phone, $company, $local_status, $existing['id']]);
                        } else {
                            $pdo->prepare("INSERT INTO crm_leads (name, email, phone, company, source, status) VALUES (?, ?, ?, ?, 'hubspot', ?)")
                                ->execute([$name, $email, $phone, $company, $local_status]);
                        }
                        $synced++;
                    } catch (Exception $e) { $hs_errors++; }
                }
                $paging = $data['paging']['next'] ?? null;
                if ($paging && isset($paging['after'])) {
                    $after = $paging['after'];
                } else {
                    $has_more = false;
                }
            }
            if (!$error_msg) {
                $success_msg = "Synced $synced contacts from HubSpot." . ($hs_errors ? " $hs_errors skipped." : '');
            }
        }
    }
}

$leads = [];
try { $leads = $pdo->query("SELECT * FROM crm_leads ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
$campaigns = [];
try { $campaigns = $pdo->query("SELECT * FROM crm_campaigns ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
$meetings = [];
try { $meetings = $pdo->query("SELECT * FROM crm_meetings ORDER BY scheduled_at DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
$deals = [];
try { $deals = $pdo->query("SELECT d.*, l.name as lead_name, c.name as client_name, c.company as client_company FROM crm_deals d LEFT JOIN crm_leads l ON d.lead_id = l.id LEFT JOIN clients c ON d.client_id = c.id ORDER BY d.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
$social_posts = [];
try { $social_posts = $pdo->query("SELECT * FROM crm_social_posts ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
$companies = [];
try { $companies = $pdo->query("SELECT * FROM crm_companies ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

$social_api = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'social_%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $social_api[$r['setting_key']] = $r['setting_value']; }
} catch(Exception $e) {}

$clients_list = [];
try { $clients_list = $pdo->query("SELECT id, name, company FROM clients WHERE status = 'active' ORDER BY company, name")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$recent_tickets = [];
try { $recent_tickets = $pdo->query("SELECT t.id, t.subject, t.status, t.priority, t.created_at, c.name as client_name, c.company FROM tickets t LEFT JOIN clients c ON t.client_id = c.id ORDER BY t.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$recent_messages = [];
try { $recent_messages = $pdo->query("SELECT cm.*, cc.name as channel_name FROM chat_messages cm LEFT JOIN chat_channels cc ON cm.room = cc.slug ORDER BY cm.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$lead_stats = ['new' => 0, 'contacted' => 0, 'qualified' => 0, 'lost' => 0, 'won' => 0];
foreach ($leads as $l) { $lead_stats[$l['status']] = ($lead_stats[$l['status']] ?? 0) + 1; }

// Lead detail data
$detail_lead = null;
$lead_comms  = [];
$lid_param   = intval($_GET['lid'] ?? 0);
if ($lid_param && $tab === 'leads') {
    foreach ($leads as $l) { if ($l['id'] == $lid_param) { $detail_lead = $l; break; } }
    if ($detail_lead) {
        try {
            $s = $pdo->prepare("SELECT cc.*, u.name as author_name FROM crm_communications cc LEFT JOIN users u ON cc.created_by = u.id WHERE cc.entity_type='lead' AND cc.entity_id=? ORDER BY cc.created_at DESC LIMIT 100");
            $s->execute([$lid_param]);
            $lead_comms = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(\Exception $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-bullhorn text-blue-500 mr-2"></i>CRM</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Leads, campaigns, meetings, and inbox</p>
                </div>
            </div>
            <div class="px-6 flex gap-1 border-t border-gray-100 overflow-x-auto">
                <?php foreach (['leads' => 'Leads', 'companies' => '<i class="fas fa-building mr-1"></i>Companies', 'deals' => '<i class="fas fa-handshake mr-1"></i>Deals', 'campaigns' => 'Campaigns', 'marketing' => '<i class="fas fa-share-alt mr-1"></i>Marketing', 'meetings' => 'Meetings', 'inbox' => 'Inbox'] as $k => $v): ?>
                    <a href="?tab=<?= $k ?>" class="px-4 py-3 text-sm font-medium border-b-2 transition whitespace-nowrap <?= $tab === $k ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>" data-testid="tab-<?= $k ?>"><?= $v ?></a>
                <?php endforeach; ?>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-3"></i><?= htmlspecialchars($success_msg) ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-3"></i><?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'leads'): ?>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                <?php
                $stat_colors = ['new' => 'blue', 'contacted' => 'yellow', 'qualified' => 'purple', 'lost' => 'red', 'won' => 'green'];
                foreach ($lead_stats as $status => $count):
                    $color = $stat_colors[$status] ?? 'gray';
                ?>
                <div class="bg-white rounded-lg border border-gray-200 p-4 text-center" data-testid="stat-<?= $status ?>">
                    <p class="text-xs font-semibold text-gray-500 uppercase"><?= ucfirst($status) ?></p>
                    <p class="text-2xl font-bold text-<?= $color ?>-600"><?= $count ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($detail_lead): ?>
            <!-- ── Lead Detail Panel ─────────────────────────────────── -->
            <div class="mb-4 flex items-center gap-2">
                <a href="?tab=leads" class="text-sm text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-1"></i>Leads</a>
                <span class="text-gray-400">/</span>
                <span class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($detail_lead['name']) ?></span>
            </div>
            <?php
            $lv = $_GET['lv'] ?? 'about';
            $lcomm_filter = $_GET['lt'] ?? '';
            $type_icons  = ['note'=>'fa-sticky-note text-yellow-500','email'=>'fa-envelope text-blue-500','call'=>'fa-phone text-green-500','meeting'=>'fa-calendar text-purple-500'];
            $type_labels = ['note'=>'Note','email'=>'Email','call'=>'Call','meeting'=>'Meeting'];
            $filtered_lead_comms = $lead_comms;
            if ($lcomm_filter) $filtered_lead_comms = array_filter($lead_comms, fn($c) => $c['type'] === $lcomm_filter);
            $sc_lead = ['new'=>'blue','contacted'=>'yellow','qualified'=>'purple','lost'=>'red','won'=>'green'][$detail_lead['status']] ?? 'gray';
            ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    <div class="bg-white rounded-xl border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center font-bold text-xl text-purple-600">
                                    <?= strtoupper(substr($detail_lead['name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-gray-900"><?= htmlspecialchars($detail_lead['name']) ?></h2>
                                    <?php if ($detail_lead['company']): ?><p class="text-sm text-gray-500"><?= htmlspecialchars($detail_lead['company']) ?></p><?php endif; ?>
                                </div>
                                <span class="ml-auto px-2.5 py-0.5 text-xs font-semibold bg-<?= $sc_lead ?>-100 text-<?= $sc_lead ?>-700 rounded-full"><?= ucfirst($detail_lead['status']) ?></span>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="flex gap-2 mb-6 border-b border-gray-100 pb-4">
                                <a href="?tab=leads&lid=<?= $detail_lead['id'] ?>&lv=about" class="px-3 py-1.5 text-sm font-medium rounded-md <?= $lv !== 'activities' ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:text-gray-700' ?>">About</a>
                                <a href="?tab=leads&lid=<?= $detail_lead['id'] ?>&lv=activities" class="px-3 py-1.5 text-sm font-medium rounded-md <?= $lv === 'activities' ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:text-gray-700' ?>">
                                    Activities <?php if (count($lead_comms) > 0): ?><span class="ml-1 bg-blue-100 text-blue-700 text-xs px-1.5 py-0.5 rounded-full"><?= count($lead_comms) ?></span><?php endif; ?>
                                </a>
                            </div>

                            <?php if ($lv === 'activities'): ?>
                            <!-- Communication Form -->
                            <div class="mb-5 bg-gray-50 rounded-xl border border-gray-200 p-4">
                                <div class="flex gap-2 mb-3" id="lead-comm-type-tabs">
                                    <?php foreach (['note'=>['fa-sticky-note','Note'],'email'=>['fa-envelope','Email'],'call'=>['fa-phone','Call'],'meeting'=>['fa-calendar','Meeting']] as $ct => [$ico, $lbl]): ?>
                                    <button type="button" onclick="switchLeadCommTab('<?= $ct ?>')"
                                        class="lead-comm-tab px-3 py-1.5 text-sm rounded-lg border transition <?= $ct === 'note' ? 'bg-white border-blue-300 text-blue-700 font-medium shadow-sm' : 'border-transparent text-gray-500 hover:text-gray-700' ?>"
                                        data-tab="<?= $ct ?>">
                                        <i class="fas <?= $ico ?> mr-1"></i><?= $lbl ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <form method="POST" id="lead-comm-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_lead_comm">
                                    <input type="hidden" name="comm_lead_id" value="<?= $detail_lead['id'] ?>">
                                    <input type="hidden" name="comm_type" id="lead-comm-type-val" value="note">
                                    <div class="mb-2">
                                        <input type="text" name="comm_subject" placeholder="Subject (optional)" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-2">
                                        <textarea name="comm_body" rows="3" placeholder="Write a note, log a call, or compose an email…" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" required></textarea>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2 mb-2" id="lead-comm-extra">
                                        <div class="lead-comm-call-field lead-comm-meeting-field hidden">
                                            <select name="comm_direction" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                                                <option value="outbound">Outbound</option><option value="inbound">Inbound</option>
                                            </select>
                                        </div>
                                        <div class="lead-comm-call-field hidden">
                                            <input type="number" name="comm_duration" placeholder="Duration (min)" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                                        </div>
                                        <div class="lead-comm-call-field hidden">
                                            <select name="comm_outcome" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                                                <option value="">Outcome…</option>
                                                <option value="connected">Connected</option><option value="voicemail">Left Voicemail</option>
                                                <option value="no_answer">No Answer</option><option value="busy">Busy</option>
                                            </select>
                                        </div>
                                        <div class="lead-comm-meeting-field hidden">
                                            <input type="datetime-local" name="comm_scheduled_at" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                                        </div>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-xs text-gray-400" id="lead-comm-hint">Log a note about this lead</span>
                                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                                            <i class="fas fa-save mr-1"></i><span id="lead-comm-btn-text">Save Note</span>
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Filter + Communications List -->
                            <div class="flex gap-2 mb-3 flex-wrap">
                                <a href="?tab=leads&lid=<?= $detail_lead['id'] ?>&lv=activities" class="px-2 py-1 text-xs rounded <?= !$lcomm_filter ? 'bg-blue-100 text-blue-700 font-semibold' : 'text-gray-500 hover:text-gray-700' ?>">All</a>
                                <?php foreach ($type_labels as $ct => $lbl): ?>
                                <a href="?tab=leads&lid=<?= $detail_lead['id'] ?>&lv=activities&lt=<?= $ct ?>" class="px-2 py-1 text-xs rounded <?= $lcomm_filter === $ct ? 'bg-blue-100 text-blue-700 font-semibold' : 'text-gray-500 hover:text-gray-700' ?>"><?= $lbl ?>s</a>
                                <?php endforeach; ?>
                            </div>

                            <?php if (empty($filtered_lead_comms)): ?>
                            <div class="py-8 text-center text-gray-400 text-sm"><i class="fas fa-comments text-2xl mb-2 block"></i>No communications logged yet.</div>
                            <?php else: foreach ($filtered_lead_comms as $cm): ?>
                            <div class="border border-gray-100 rounded-lg p-4 mb-3 hover:bg-gray-50">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-2 flex-1 min-w-0">
                                        <i class="fas <?= $type_icons[$cm['type']] ?? 'fa-comment text-gray-400' ?> text-base flex-shrink-0"></i>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-sm font-semibold text-gray-900"><?= ucfirst($cm['type']) ?></span>
                                                <?php if ($cm['direction']): ?><span class="text-xs px-1.5 py-0.5 rounded <?= $cm['direction']==='inbound' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' ?>"><?= ucfirst($cm['direction']) ?></span><?php endif; ?>
                                                <?php if ($cm['subject']): ?><span class="text-sm text-gray-700 truncate">· <?= htmlspecialchars($cm['subject']) ?></span><?php endif; ?>
                                            </div>
                                            <p class="text-sm text-gray-600 mt-1"><?= nl2br(htmlspecialchars($cm['body'])) ?></p>
                                            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($cm['author_name'] ?? 'Admin') ?> · <?= date('M d, Y g:i a', strtotime($cm['created_at'])) ?></p>
                                        </div>
                                    </div>
                                    <form method="POST" class="flex-shrink-0" onsubmit="return confirm('Delete this entry?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_lead_comm">
                                        <input type="hidden" name="comm_id" value="<?= $cm['id'] ?>">
                                        <input type="hidden" name="comm_lead_id" value="<?= $detail_lead['id'] ?>">
                                        <button type="submit" class="text-gray-300 hover:text-red-500 text-xs"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>

                            <?php else: /* About tab */ ?>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <?php $lead_fields = ['Email'=>$detail_lead['email']??'','Phone'=>$detail_lead['phone']??'','Company'=>$detail_lead['company']??'','Source'=>$detail_lead['source']??'','Industry'=>$detail_lead['industry']??'','Employees'=>$detail_lead['employee_count']??'','Lead Score'=>$detail_lead['lead_score']??'','Service Interest'=>$detail_lead['service_interest']??'','Geography'=>$detail_lead['geography']??'','Next Action'=>$detail_lead['next_action_date']??'']; ?>
                                <?php foreach ($lead_fields as $lf_label => $lf_val): if ($lf_val): ?>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase mb-0.5"><?= $lf_label ?></p>
                                    <p class="text-gray-900"><?= htmlspecialchars($lf_val) ?></p>
                                </div>
                                <?php endif; endforeach; ?>
                            </div>
                            <?php if ($detail_lead['notes']): ?>
                            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <p class="text-xs font-semibold text-yellow-700 mb-1">Notes</p>
                                <p class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($detail_lead['notes'])) ?></p>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Lead sidebar -->
                <div class="space-y-4">
                    <div class="bg-white rounded-xl border border-gray-200 p-4">
                        <h3 class="text-sm font-semibold text-gray-700 mb-3">Quick Actions</h3>
                        <div class="flex flex-col gap-2">
                            <a href="?tab=leads&lid=<?= $detail_lead['id'] ?>&lv=activities" class="px-3 py-2 bg-blue-50 hover:bg-blue-100 text-blue-700 text-sm rounded-lg text-center transition">📝 Log Activity</a>
                            <?php if ($detail_lead['status'] !== 'won'): ?>
                            <form method="POST" onsubmit="return confirm('Convert this lead to a client?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="convert_lead">
                                <input type="hidden" name="lead_id" value="<?= $detail_lead['id'] ?>">
                                <button type="submit" class="w-full px-3 py-2 bg-green-50 hover:bg-green-100 text-green-700 text-sm rounded-lg transition">✅ Convert to Client</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-4">
                        <h3 class="text-sm font-semibold text-gray-700 mb-3">Lead Info</h3>
                        <div class="space-y-2 text-sm">
                            <?php if ($detail_lead['email']): ?><div><span class="text-gray-500">Email: </span><a href="mailto:<?= htmlspecialchars($detail_lead['email']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($detail_lead['email']) ?></a></div><?php endif; ?>
                            <?php if ($detail_lead['phone']): ?><div><span class="text-gray-500">Phone: </span><?= htmlspecialchars($detail_lead['phone']) ?></div><?php endif; ?>
                            <?php if ($detail_lead['source']): ?><div><span class="text-gray-500">Source: </span><?= htmlspecialchars(ucfirst($detail_lead['source'])) ?></div><?php endif; ?>
                            <div><span class="text-gray-500">Created: </span><?= date('M d, Y', strtotime($detail_lead['created_at'])) ?></div>
                            <div><span class="text-gray-500">Activities: </span><strong><?= count($lead_comms) ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: /* leads list */ ?>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">All Leads</h2>
                <div class="flex items-center gap-2">
                    <form method="POST" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="sync_hubspot">
                        <button type="submit" class="px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-lg text-sm font-medium transition" data-testid="button-sync-hubspot">
                            <i class="fas fa-sync mr-1"></i>Sync HubSpot
                        </button>
                    </form>
                    <button onclick="document.getElementById('add-lead-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-lead">
                        <i class="fas fa-plus mr-1"></i>Add Lead
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <?php if (empty($leads)): ?>
                    <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-user-plus text-gray-300 text-2xl mb-2 block"></i>No leads yet. Click "Add Lead" to get started.</div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-leads">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Email</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Company</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Source</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Created</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($leads as $lead):
                                $sc = ['new'=>'blue','contacted'=>'yellow','qualified'=>'purple','lost'=>'red','won'=>'green'][$lead['status']] ?? 'gray';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="lead-row-<?= $lead['id'] ?>">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($lead['name']) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($lead['email'] ?? '') ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($lead['company'] ?? '') ?></td>
                                <td class="px-4 py-3"><span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded"><?= htmlspecialchars($lead['source']) ?></span></td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_lead_status">
                                        <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="text-xs border rounded px-2 py-1 bg-<?= $sc ?>-50 text-<?= $sc ?>-700 border-<?= $sc ?>-200" data-testid="select-lead-status-<?= $lead['id'] ?>">
                                            <?php foreach (['new','contacted','qualified','lost','won'] as $s): ?>
                                                <option value="<?= $s ?>" <?= $lead['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500"><?= date('M d, Y', strtotime($lead['created_at'])) ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <a href="?tab=leads&lid=<?= $lead['id'] ?>" class="text-blue-600 hover:text-blue-800 text-sm" title="View Lead" data-testid="button-view-lead-<?= $lead['id'] ?>"><i class="fas fa-eye"></i></a>
                                        <?php if ($lead['status'] !== 'won'): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Convert this lead to a client?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="convert_lead">
                                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                                            <button type="submit" class="text-green-600 hover:text-green-800 text-sm" title="Convert to Client" data-testid="button-convert-<?= $lead['id'] ?>"><i class="fas fa-user-check"></i></button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this lead?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_lead">
                                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800 text-sm" title="Delete" data-testid="button-delete-lead-<?= $lead['id'] ?>"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div id="add-lead-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg w-full max-w-xl mx-4 shadow-xl flex flex-col max-h-[90vh]">
                    <div class="flex items-center justify-between px-6 py-4 border-b flex-shrink-0">
                        <h3 class="text-lg font-semibold">Add New Lead</h3>
                        <button onclick="document.getElementById('add-lead-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4 overflow-y-auto">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_lead">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                            <input type="text" name="lead_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-name">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input type="email" name="lead_email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-email">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="text" name="lead_phone" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-phone">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                                <input type="text" name="lead_company" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-company">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
                                <select name="lead_source" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-lead-source">
                                    <option value="manual">Manual</option>
                                    <option value="website">Website</option>
                                    <option value="referral">Referral</option>
                                    <option value="cold_call">Cold Call</option>
                                    <option value="social_media">Social Media</option>
                                    <option value="advertisement">Advertisement</option>
                                    <option value="email_campaign">Email Campaign</option>
                                    <option value="partner">Partner</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Industry</label>
                                <select name="lead_industry" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-lead-industry">
                                    <option value="">-- Select --</option>
                                    <option>Technology</option><option>Healthcare</option><option>Finance</option><option>Education</option>
                                    <option>Manufacturing</option><option>Retail</option><option>Real Estate</option><option>Government</option><option>Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Employees</label>
                                <select name="lead_employee_count" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-lead-employees">
                                    <option value="">-- Select --</option>
                                    <option>1-10</option><option>11-50</option><option>51-200</option><option>201-500</option><option>500+</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Service Interest</label>
                                <select name="lead_service_interest" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-lead-service">
                                    <option value="">-- Select --</option>
                                    <option>Fiber Internet</option><option>VoIP Phone</option><option>Managed IT</option><option>Cloud Hosting</option>
                                    <option>Cybersecurity</option><option>Microsoft 365</option><option>Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Geography</label>
                                <input type="text" name="lead_geography" placeholder="City, State or Region" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-geography">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Lead Score (0-100)</label>
                                <input type="number" name="lead_score" min="0" max="100" value="50" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-score">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Next Action Date</label>
                                <input type="date" name="lead_next_action_date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-next-action">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea name="lead_notes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-lead-notes"></textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="document.getElementById('add-lead-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-lead">Add Lead</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; /* if ($detail_lead) */ ?>
            <?php endif; /* if ($tab === 'leads') */ ?>

            <?php if ($tab === 'companies'): ?>
            <?php
            $company_search = $_GET['csearch'] ?? '';
            $filtered_companies = $companies;
            if ($company_search) {
                $filtered_companies = array_filter($companies, fn($c) =>
                    stripos($c['name'], $company_search) !== false ||
                    stripos($c['city'] ?? '', $company_search) !== false ||
                    stripos($c['industry'] ?? '', $company_search) !== false
                );
            }
            $detail_id = intval($_GET['cid'] ?? 0);
            $detail_company = null;
            $company_contacts = [];
            if ($detail_id) {
                foreach ($companies as $c) { if ($c['id'] == $detail_id) { $detail_company = $c; break; } }
                if ($detail_company) {
                    try { $company_contacts = $pdo->prepare("SELECT id, name, email, phone, company, client_code FROM clients WHERE crm_company_id = ? ORDER BY name")->execute([$detail_id]) ? $pdo->query("SELECT id, name, email, phone, company, client_code FROM clients WHERE crm_company_id = $detail_id ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : []; } catch(\Exception $e) {}
                }
                $company_comms = [];
                try {
                    $s = $pdo->prepare("SELECT cc.*, u.name as author_name FROM crm_communications cc LEFT JOIN users u ON cc.created_by = u.id WHERE cc.entity_type='company' AND cc.entity_id=? ORDER BY cc.created_at DESC LIMIT 100");
                    $s->execute([$detail_id]);
                    $company_comms = $s->fetchAll(PDO::FETCH_ASSOC);
                } catch(\Exception $e) {}
            }
            ?>
            <?php if ($detail_company): ?>
            <!-- Company Detail View -->
            <div class="mb-4 flex items-center gap-2">
                <a href="?tab=companies" class="text-sm text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-1"></i>Companies</a>
                <span class="text-gray-400">/</span>
                <span class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($detail_company['name']) ?></span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    <div class="bg-white rounded-xl border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center font-bold text-xl text-blue-600">
                                    <?= strtoupper(substr($detail_company['name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-gray-900"><?= htmlspecialchars($detail_company['name']) ?></h2>
                                    <?php if ($detail_company['industry']): ?>
                                    <p class="text-sm text-gray-500"><?= htmlspecialchars($detail_company['industry']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="flex gap-2 mb-6 border-b border-gray-100 pb-4">
                                <a href="?tab=companies&cid=<?= $detail_company['id'] ?>&cv=about" class="px-3 py-1.5 text-sm font-medium rounded-md <?= ($_GET['cv'] ?? 'about') !== 'activities' ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:text-gray-700' ?>">About</a>
                                <a href="?tab=companies&cid=<?= $detail_company['id'] ?>&cv=activities" class="px-3 py-1.5 text-sm font-medium rounded-md <?= ($_GET['cv'] ?? '') === 'activities' ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:text-gray-700' ?>">Activities</a>
                            </div>
                            <?php if (($_GET['cv'] ?? 'about') === 'activities'): ?>
                            <?php
                            $comm_type_filter = $_GET['ct'] ?? '';
                            $type_icons = ['note'=>'fa-sticky-note text-yellow-500','email'=>'fa-envelope text-blue-500','call'=>'fa-phone text-green-500','meeting'=>'fa-calendar text-purple-500','task'=>'fa-tasks text-orange-500'];
                            $type_labels = ['note'=>'Note','email'=>'Email','call'=>'Call','meeting'=>'Meeting','task'=>'Task'];
                            $filtered_comms = $company_comms;
                            if ($comm_type_filter) $filtered_comms = array_filter($company_comms, fn($c) => $c['type'] === $comm_type_filter);
                            ?>
                            <!-- Add Communication Form -->
                            <div class="mb-5 bg-gray-50 rounded-xl border border-gray-200 p-4">
                                <div class="flex gap-2 mb-3" id="comm-type-tabs">
                                    <?php foreach (['note'=>['fa-sticky-note','Note'],'email'=>['fa-envelope','Email'],'call'=>['fa-phone','Call'],'meeting'=>['fa-calendar','Meeting']] as $ct => [$ico, $lbl]): ?>
                                    <button type="button" onclick="switchCommType('<?= $ct ?>')" class="comm-type-btn px-3 py-1.5 text-xs font-medium rounded-lg border transition <?= $ct === 'note' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400' ?>" data-type="<?= $ct ?>" data-testid="btn-comm-type-<?= $ct ?>">
                                        <i class="fas <?= $ico ?> mr-1"></i><?= $lbl ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <form method="POST" class="space-y-3">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_company_comm">
                                    <input type="hidden" name="comm_company_id" value="<?= $detail_company['id'] ?>">
                                    <input type="hidden" name="comm_type" id="comm_type_val" value="note">
                                    <div id="comm-subject-row">
                                        <input type="text" name="comm_subject" placeholder="Subject..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-comm-subject">
                                    </div>
                                    <textarea name="comm_body" rows="3" required placeholder="Add a note..." id="comm_body_area" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-comm-body"></textarea>
                                    <div id="comm-extra-fields" class="hidden grid grid-cols-2 gap-3">
                                        <div id="comm-duration-field">
                                            <label class="text-xs text-gray-600 mb-1 block">Duration (min)</label>
                                            <input type="number" name="comm_duration" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-comm-duration">
                                        </div>
                                        <div id="comm-outcome-field">
                                            <label class="text-xs text-gray-600 mb-1 block">Outcome</label>
                                            <select name="comm_outcome" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-comm-outcome">
                                                <option value="">— Select —</option>
                                                <option>Connected</option><option>Left Voicemail</option><option>No Answer</option><option>Interested</option><option>Not Interested</option><option>Follow Up</option>
                                            </select>
                                        </div>
                                        <div id="comm-scheduled-field" class="col-span-2">
                                            <label class="text-xs text-gray-600 mb-1 block">Scheduled At</label>
                                            <input type="datetime-local" name="comm_scheduled_at" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-comm-scheduled">
                                        </div>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <div class="flex gap-2 items-center text-xs text-gray-500">
                                            <label class="flex items-center gap-1"><input type="radio" name="comm_direction" value="outbound" checked class="accent-blue-600"> Outbound</label>
                                            <label class="flex items-center gap-1"><input type="radio" name="comm_direction" value="inbound" class="accent-blue-600"> Inbound</label>
                                        </div>
                                        <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" data-testid="button-submit-comm">Log Activity</button>
                                    </div>
                                </form>
                            </div>
                            <script>
                            function switchCommType(type) {
                                document.getElementById('comm_type_val').value = type;
                                document.querySelectorAll('.comm-type-btn').forEach(b => {
                                    b.classList.toggle('bg-blue-600', b.dataset.type === type);
                                    b.classList.toggle('text-white', b.dataset.type === type);
                                    b.classList.toggle('border-blue-600', b.dataset.type === type);
                                    b.classList.toggle('bg-white', b.dataset.type !== type);
                                    b.classList.toggle('text-gray-600', b.dataset.type !== type);
                                    b.classList.toggle('border-gray-300', b.dataset.type !== type);
                                });
                                const placeholders = {note:'Add a note...',email:'Write your email...',call:'Call summary...',meeting:'Meeting notes...'};
                                document.getElementById('comm_body_area').placeholder = placeholders[type] || 'Details...';
                                const extraFields = document.getElementById('comm-extra-fields');
                                if (type === 'call' || type === 'meeting') { extraFields.classList.remove('hidden'); extraFields.classList.add('grid'); }
                                else { extraFields.classList.add('hidden'); extraFields.classList.remove('grid'); }
                            }
                            </script>
                            <!-- Filter Bar -->
                            <div class="flex gap-2 flex-wrap mb-3">
                                <a href="?tab=companies&cid=<?= $detail_company['id'] ?>&cv=activities" class="px-2 py-1 text-xs rounded <?= !$comm_type_filter ? 'bg-blue-100 text-blue-700 font-semibold' : 'text-gray-500 hover:text-gray-700' ?>">All</a>
                                <?php foreach ($type_labels as $ct => $lbl): ?>
                                <a href="?tab=companies&cid=<?= $detail_company['id'] ?>&cv=activities&ct=<?= $ct ?>" class="px-2 py-1 text-xs rounded <?= $comm_type_filter === $ct ? 'bg-blue-100 text-blue-700 font-semibold' : 'text-gray-500 hover:text-gray-700' ?>"><?= $lbl ?>s</a>
                                <?php endforeach; ?>
                                <span class="ml-auto text-xs text-gray-400"><?= count($filtered_comms) ?> activities</span>
                            </div>
                            <!-- Activities List -->
                            <?php if (empty($filtered_comms)): ?>
                            <div class="text-center py-8 text-gray-400">
                                <i class="fas fa-history text-3xl mb-2 block opacity-40"></i>
                                <p class="text-sm">No activities<?= $comm_type_filter ? ' of this type' : '' ?> yet</p>
                            </div>
                            <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($filtered_comms as $cm): ?>
                                <div class="bg-white rounded-xl border border-gray-200 p-4" data-testid="comm-item-<?= $cm['id'] ?>">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <i class="fas <?= $type_icons[$cm['type']] ?? 'fa-circle text-gray-400' ?> text-sm"></i>
                                            <span class="text-xs font-semibold text-gray-700 uppercase"><?= $type_labels[$cm['type']] ?? $cm['type'] ?></span>
                                            <?php if ($cm['direction']): ?>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded-full <?= $cm['direction'] === 'inbound' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' ?>"><?= ucfirst($cm['direction']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[11px] text-gray-400"><?= date('M d, Y g:i A', strtotime($cm['created_at'])) ?></span>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this entry?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_company_comm">
                                                <input type="hidden" name="comm_id" value="<?= $cm['id'] ?>">
                                                <input type="hidden" name="comm_company_id" value="<?= $detail_company['id'] ?>">
                                                <button type="submit" class="text-gray-300 hover:text-red-500 text-xs"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php if ($cm['subject']): ?>
                                    <p class="text-sm font-semibold text-gray-900 mt-2"><?= htmlspecialchars($cm['subject']) ?></p>
                                    <?php endif; ?>
                                    <p class="text-sm text-gray-700 mt-1 whitespace-pre-wrap"><?= htmlspecialchars($cm['body']) ?></p>
                                    <?php if ($cm['duration_minutes'] || $cm['outcome']): ?>
                                    <div class="flex gap-4 mt-2 text-xs text-gray-400">
                                        <?php if ($cm['duration_minutes']): ?><span><i class="fas fa-clock mr-1"></i><?= $cm['duration_minutes'] ?> min</span><?php endif; ?>
                                        <?php if ($cm['outcome']): ?><span><i class="fas fa-flag mr-1"></i><?= htmlspecialchars($cm['outcome']) ?></span><?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <p class="text-[11px] text-gray-400 mt-2">by <?= htmlspecialchars($cm['author_name'] ?? 'System') ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-3">Company Profile</h3>
                                <div class="grid grid-cols-2 gap-4">
                                    <?php $fields = [
                                        'City' => $detail_company['city'],
                                        'Street Address' => $detail_company['address'],
                                        'Postal Code' => $detail_company['postal_code'],
                                        'State/Region' => $detail_company['state'],
                                        'Country/Region' => $detail_company['country'],
                                        'Industry' => $detail_company['industry'],
                                        'Employees' => $detail_company['employee_count'],
                                        'Website' => $detail_company['website'],
                                        'Phone' => $detail_company['phone'],
                                        'Email' => $detail_company['email'],
                                    ];
                                    foreach ($fields as $label => $value): if (!$value) continue; ?>
                                    <div>
                                        <p class="text-xs text-gray-400 uppercase font-semibold"><?= $label ?></p>
                                        <?php if ($label === 'Website'): ?>
                                        <a href="<?= htmlspecialchars($value) ?>" target="_blank" class="text-sm text-blue-600 hover:underline"><?= htmlspecialchars($value) ?></a>
                                        <?php elseif ($label === 'Email'): ?>
                                        <a href="mailto:<?= htmlspecialchars($value) ?>" class="text-sm text-blue-600 hover:underline"><?= htmlspecialchars($value) ?></a>
                                        <?php else: ?>
                                        <p class="text-sm text-gray-700"><?= htmlspecialchars($value) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($detail_company['notes']): ?>
                                <div class="mt-4 pt-4 border-t border-gray-100">
                                    <p class="text-xs text-gray-400 uppercase font-semibold mb-1">Notes</p>
                                    <p class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($detail_company['notes'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="space-y-4">
                    <div class="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Key Information</h3>
                        <div class="space-y-3">
                            <?php $kv = [
                                'Company Owner' => $detail_company['company_owner'],
                                'City' => $detail_company['city'],
                                'Lifecycle Stage' => ucfirst($detail_company['lifecycle_stage'] ?? ''),
                                'Lead Status' => $detail_company['lead_status'],
                                'Industry' => $detail_company['industry'],
                                'Last Contacted' => $detail_company['last_contacted'] ? date('M d, Y', strtotime($detail_company['last_contacted'])) : null,
                            ];
                            foreach ($kv as $k => $v): ?>
                            <div>
                                <p class="text-[11px] text-gray-400 uppercase font-semibold"><?= $k ?></p>
                                <p class="text-sm text-gray-700 mt-0.5"><?= $v ? htmlspecialchars($v) : '<span class="text-gray-300">—</span>' ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-900">Contacts (<?= count($company_contacts) ?>)</h3>
                            <a href="admin-clients.php" class="text-xs text-blue-600 hover:underline">+ Add</a>
                        </div>
                        <?php if (empty($company_contacts)): ?>
                        <p class="text-xs text-gray-400 italic">No contacts linked yet. Link a contact from their client detail page.</p>
                        <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($company_contacts as $ct): ?>
                            <div class="flex items-start gap-2" data-testid="contact-item-<?= $ct['id'] ?>">
                                <div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-600 flex-shrink-0 mt-0.5">
                                    <?= strtoupper(substr($ct['name'], 0, 1)) ?>
                                </div>
                                <div class="min-w-0">
                                    <a href="admin-client-detail.php?id=<?= $ct['id'] ?>" class="text-sm font-semibold text-gray-900 hover:text-blue-600 truncate block" data-testid="link-contact-<?= $ct['id'] ?>"><?= htmlspecialchars($ct['name']) ?></a>
                                    <?php $cc = !empty($ct['client_code']) ? $ct['client_code'] : ('BL' . (100000 + $ct['id'])); ?>
                                    <span class="text-[10px] font-mono text-blue-500"><?= $cc ?></span>
                                    <?php if ($ct['email']): ?>
                                    <a href="mailto:<?= htmlspecialchars($ct['email']) ?>" class="text-[11px] text-gray-400 hover:text-blue-500 truncate block"><?= htmlspecialchars($ct['email']) ?></a>
                                    <?php endif; ?>
                                    <?php if ($ct['phone']): ?>
                                    <span class="text-[11px] text-gray-400"><?= htmlspecialchars($ct['phone']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-900">Deals (0)</h3>
                            <a href="?tab=deals" class="text-xs text-blue-600 hover:underline">+ Add</a>
                        </div>
                        <p class="text-xs text-gray-400 italic">Track the revenue opportunities associated with this record.</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-900">Tickets (0)</h3>
                            <a href="admin-tickets.php" class="text-xs text-blue-600 hover:underline">+ Add</a>
                        </div>
                        <p class="text-xs text-gray-400 italic">Track customer requests associated with this record.</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-5">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold text-gray-900">Actions</h3>
                        </div>
                        <form method="POST" onsubmit="return confirm('Delete this company?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_company">
                            <input type="hidden" name="company_id" value="<?= $detail_company['id'] ?>">
                            <button type="submit" class="w-full px-3 py-2 bg-red-50 hover:bg-red-100 text-red-600 text-sm rounded-lg transition" data-testid="button-delete-company-<?= $detail_company['id'] ?>">
                                <i class="fas fa-trash mr-2"></i>Delete Company
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- Companies List View -->
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <h2 class="text-lg font-semibold text-gray-900">Companies</h2>
                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full font-semibold"><?= count($filtered_companies) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <form method="GET" class="flex gap-2">
                        <input type="hidden" name="tab" value="companies">
                        <input type="text" name="csearch" value="<?= htmlspecialchars($company_search) ?>" placeholder="Search companies..." class="px-3 py-2 border border-gray-300 rounded-lg text-sm w-52 focus:ring-2 focus:ring-blue-400" data-testid="input-company-search">
                        <button type="submit" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm"><i class="fas fa-search"></i></button>
                        <?php if ($company_search): ?>
                        <a href="?tab=companies" class="px-3 py-2 bg-gray-200 hover:bg-gray-300 text-gray-600 rounded-lg text-sm"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                    <button onclick="document.getElementById('add-company-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-add-company">
                        <i class="fas fa-plus mr-1"></i>Add Company
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <?php if (empty($filtered_companies)): ?>
                    <div class="p-12 text-center text-gray-400">
                        <i class="fas fa-building text-5xl mb-4 block opacity-30"></i>
                        <p class="font-semibold text-gray-600">No companies yet</p>
                        <p class="text-sm mt-1">Add your first company to start tracking</p>
                    </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-companies">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-left"><input type="checkbox" class="rounded border-gray-300"></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Company Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Create Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Phone</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">City</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Country/Region</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Industry</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Stage</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($filtered_companies as $co):
                                $lc_colors = ['lead'=>'blue','prospect'=>'purple','customer'=>'green','churned'=>'red','other'=>'gray'];
                                $lc = $co['lifecycle_stage'] ?? 'lead';
                                $lcc = $lc_colors[$lc] ?? 'gray';
                            ?>
                            <tr class="hover:bg-gray-50 transition" data-testid="company-row-<?= $co['id'] ?>">
                                <td class="px-4 py-3"><input type="checkbox" class="rounded border-gray-300"></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center font-bold text-sm text-blue-600 flex-shrink-0">
                                            <?= strtoupper(substr($co['name'], 0, 1)) ?>
                                        </div>
                                        <a href="?tab=companies&cid=<?= $co['id'] ?>" class="text-sm font-semibold text-blue-600 hover:underline" data-testid="link-company-<?= $co['id'] ?>"><?= htmlspecialchars($co['name']) ?></a>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= date('M d, Y g:i A T', strtotime($co['created_at'])) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= $co['phone'] ? htmlspecialchars($co['phone']) : '<span class="text-gray-300">—</span>' ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= $co['city'] ? htmlspecialchars($co['city']) : '<span class="text-gray-300">—</span>' ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= $co['country'] ? htmlspecialchars($co['country']) : '<span class="text-gray-300">—</span>' ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= $co['industry'] ? htmlspecialchars($co['industry']) : '<span class="text-gray-300">—</span>' ?></td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_company_lifecycle">
                                        <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                                        <select name="lifecycle_stage" onchange="this.form.submit()" class="text-xs border rounded px-2 py-1 bg-<?= $lcc ?>-50 text-<?= $lcc ?>-700 border-<?= $lcc ?>-200" data-testid="select-company-lifecycle-<?= $co['id'] ?>">
                                            <?php foreach (['lead'=>'Lead','prospect'=>'Prospect','customer'=>'Customer','churned'=>'Churned','other'=>'Other'] as $ls => $lsl): ?>
                                                <option value="<?= $ls ?>" <?= ($co['lifecycle_stage'] ?? 'lead') === $ls ? 'selected' : '' ?>><?= $lsl ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center gap-2 justify-end">
                                        <a href="?tab=companies&cid=<?= $co['id'] ?>" class="text-blue-600 hover:text-blue-800 text-sm" title="View" data-testid="button-view-company-<?= $co['id'] ?>"><i class="fas fa-eye"></i></a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this company?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_company">
                                            <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                                            <button type="submit" class="text-red-400 hover:text-red-600 text-sm" data-testid="button-delete-company-<?= $co['id'] ?>"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-2 border-t border-gray-100 text-xs text-gray-500">
                    Showing <?= count($filtered_companies) ?> <?= count($filtered_companies) === 1 ? 'company' : 'companies' ?> &nbsp;·&nbsp; 25 per page
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Company Modal -->
            <div id="add-company-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl w-full max-w-xl shadow-2xl flex flex-col max-h-[92vh]">
                    <div class="flex items-center justify-between px-5 py-4 border-b flex-shrink-0">
                        <h3 class="text-lg font-semibold text-gray-900">New Organisation</h3>
                        <button onclick="document.getElementById('add-company-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="POST" id="coForm" class="overflow-y-auto">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_company">
                        <input type="hidden" name="co_phones_json"  id="co_phones_json"  value="[]">
                        <input type="hidden" name="co_emails_json"  id="co_emails_json"  value="[]">
                        <input type="hidden" name="co_socials_json" id="co_socials_json" value="[]">

                        <div class="p-5 space-y-4">
                            <!-- Name -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
                                <input type="text" name="company_name" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-company-name" autofocus>
                            </div>

                            <!-- Tags -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tags</label>
                                <div class="border border-gray-300 rounded-md px-3 py-2 min-h-[38px] flex flex-wrap gap-1 items-center cursor-text" id="coTagContainer" onclick="document.getElementById('coTagInput').focus()">
                                    <input id="coTagInput" class="outline-none text-sm flex-1 min-w-[80px] bg-transparent" placeholder="Type and press Enter…" data-testid="input-company-tags">
                                </div>
                                <input type="hidden" name="company_tags" id="coTagsHidden">
                            </div>

                            <!-- Lifecycle + Industry row -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Lifecycle Stage</label>
                                    <select name="company_lifecycle" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="select-company-lifecycle">
                                        <option value="lead">Lead</option><option value="prospect">Prospect</option>
                                        <option value="customer">Customer</option><option value="churned">Churned</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Industry</label>
                                    <select name="company_industry" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="select-company-industry">
                                        <option value="">— Select —</option>
                                        <option>Technology</option><option>Healthcare</option><option>Finance</option>
                                        <option>Education</option><option>Manufacturing</option><option>Retail</option>
                                        <option>Real Estate</option><option>Government</option>
                                        <option>Management Consulting</option><option>Consumer Services</option>
                                        <option>Cosmetics</option><option>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Employees</label>
                                    <select name="company_employee_count" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="select-company-employees">
                                        <option value="">— Select —</option>
                                        <option>1-10</option><option>11-50</option><option>51-200</option><option>201-500</option><option>500+</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Owner</label>
                                    <input type="text" name="company_owner" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-company-owner">
                                </div>
                            </div>

                            <!-- Contact Details -->
                            <div class="border-t border-gray-100 pt-4">
                                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Contact Details</div>

                                <div class="space-y-3">
                                    <!-- Phone Numbers -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone Numbers</label>
                                        <div id="co-phones-container"></div>
                                        <span class="text-xs text-blue-600 cursor-pointer hover:underline" onclick="coAddRow('phones')" data-testid="button-co-add-phone">+ Add another phone number</span>
                                    </div>
                                    <!-- Email Addresses -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Email Addresses</label>
                                        <div id="co-emails-container"></div>
                                        <span class="text-xs text-blue-600 cursor-pointer hover:underline" onclick="coAddRow('emails')" data-testid="button-co-add-email">+ Add another email address</span>
                                    </div>
                                    <!-- Websites & Social Networks -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Websites &amp; Social Networks</label>
                                        <div id="co-socials-container"></div>
                                        <span class="text-xs text-blue-600 cursor-pointer hover:underline" onclick="coAddRow('socials')" data-testid="button-co-add-social">+ Add another website address</span>
                                    </div>
                                    <!-- Address -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                                        <input type="text" name="company_address" placeholder="Street address" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none mb-2" data-testid="input-company-address">
                                        <div class="grid grid-cols-3 gap-2">
                                            <input type="text" name="company_city"        placeholder="City"    class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-company-city">
                                            <input type="text" name="company_state"       placeholder="State"   class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-company-state">
                                            <input type="text" name="company_postal_code" placeholder="ZIP"     class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-company-postal">
                                        </div>
                                        <input type="text" name="company_country" value="United States" class="mt-2 w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-company-country">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                <textarea name="company_notes" rows="2" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="textarea-company-notes"></textarea>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 px-5 py-4 border-t bg-gray-50 rounded-b-xl flex-shrink-0">
                            <button type="button" onclick="document.getElementById('add-company-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium" data-testid="button-submit-company">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            /* Company modal dynamic rows */
            const coRowConfig = {
                phones:  { types: ['Work','Mobile','Home','Other'],   placeholder: 'Phone number', jsonKey: 'co_phones_json',  containerId: 'co-phones-container' },
                emails:  { types: ['Work','Personal','Other'],         placeholder: 'Email address', jsonKey: 'co_emails_json',  containerId: 'co-emails-container' },
                socials: { types: ['Website','LinkedIn','Twitter / X','Facebook','Instagram','GitHub','Other'], placeholder: 'URL', jsonKey: 'co_socials_json', containerId: 'co-socials-container' },
            };
            const coState = { phones: [], emails: [], socials: [] };

            function coSyncJSON() {
                ['phones','emails','socials'].forEach(k => {
                    document.getElementById('co_' + k + '_json').value = JSON.stringify(coState[k].filter(r=>r.value));
                });
            }

            function coAddRow(kind, value='', type='') {
                const idx = coState[kind].length;
                coState[kind].push({ value, type: type || coRowConfig[kind].types[0] });
                const container = document.getElementById(coRowConfig[kind].containerId);
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:6px';

                const inp = document.createElement('input');
                inp.type = kind === 'emails' ? 'email' : (kind === 'socials' ? 'url' : 'text');
                inp.placeholder = coRowConfig[kind].placeholder;
                inp.value = value;
                inp.style.cssText = 'flex:1;border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;font-size:13px;outline:none';
                inp.addEventListener('input', () => { coState[kind][idx].value = inp.value; coSyncJSON(); });

                const sel = document.createElement('select');
                sel.style.cssText = 'width:130px;border:1px solid #d1d5db;border-radius:6px;padding:6px 8px;font-size:13px;background:white;outline:none';
                coRowConfig[kind].types.forEach(t => { const o=document.createElement('option');o.value=t;o.textContent=t;sel.appendChild(o); });
                if (type) sel.value = type;
                coState[kind][idx].type = sel.value;
                sel.addEventListener('change', () => { coState[kind][idx].type = sel.value; coSyncJSON(); });

                const rm = document.createElement('span');
                rm.innerHTML = '✕'; rm.title='Remove';
                rm.style.cssText = 'cursor:pointer;color:#9ca3af;font-size:14px;flex-shrink:0';
                rm.onmouseenter = ()=>rm.style.color='#ef4444'; rm.onmouseleave = ()=>rm.style.color='#9ca3af';
                rm.addEventListener('click', () => { coState[kind].splice(idx,1); row.remove(); coSyncJSON(); });

                row.appendChild(inp); row.appendChild(sel); row.appendChild(rm);
                container.appendChild(row);
                coSyncJSON();
            }
            coAddRow('phones'); coAddRow('emails'); coAddRow('socials');

            document.getElementById('coForm').addEventListener('submit', coSyncJSON);

            /* Company Tags */
            const coTags = [];
            const coTagInput = document.getElementById('coTagInput');
            function coAddTag(v) {
                v=v.trim(); if(!v||coTags.includes(v))return; coTags.push(v);
                const chip=document.createElement('span');
                chip.style.cssText='display:inline-flex;align-items:center;gap:3px;background:#eff6ff;color:#1d4ed8;border-radius:9999px;padding:2px 10px;font-size:12px;margin:2px';
                chip.innerHTML=v+' <button type="button" style="color:#93c5fd;font-size:10px" onclick="coRemoveTag(\''+v.replace(/'/g,"\\'")+"',this.parentElement)\">✕</button>";
                document.getElementById('coTagContainer').insertBefore(chip,coTagInput);
                document.getElementById('coTagsHidden').value=coTags.join(',');
            }
            function coRemoveTag(v,chip){const i=coTags.indexOf(v);if(i!==-1)coTags.splice(i,1);chip.remove();document.getElementById('coTagsHidden').value=coTags.join(',');}
            coTagInput.addEventListener('keydown',e=>{if(['Enter','Tab',','].includes(e.key)){e.preventDefault();coAddTag(coTagInput.value);coTagInput.value='';}});
            coTagInput.addEventListener('blur',()=>{if(coTagInput.value){coAddTag(coTagInput.value);coTagInput.value='';}});
            </script>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($tab === 'deals'): ?>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Sales Pipeline</h2>
                <button onclick="document.getElementById('add-deal-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-add-deal">
                    <i class="fas fa-plus mr-1"></i>Add Deal
                </button>
            </div>
            <?php
            $stages = ['prospecting'=>'Prospecting','proposal'=>'Proposal','negotiation'=>'Negotiation','closed_won'=>'Won','closed_lost'=>'Lost'];
            $stage_colors = ['prospecting'=>'blue','proposal'=>'purple','negotiation'=>'yellow','closed_won'=>'green','closed_lost'=>'red'];
            $deals_by_stage = [];
            foreach ($deals as $d) { $deals_by_stage[$d['stage']][] = $d; }
            ?>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-4">
                <?php foreach ($stages as $stageKey => $stageLabel): $col = $stage_colors[$stageKey]; ?>
                <div class="bg-gray-50 rounded-xl border border-gray-200 min-h-[220px] flex flex-col" data-testid="pipeline-col-<?= $stageKey ?>">
                    <div class="flex items-center justify-between px-3 py-2 border-b border-gray-200">
                        <span class="text-xs font-semibold text-<?= $col ?>-700 uppercase tracking-wide"><?= $stageLabel ?></span>
                        <span class="text-xs bg-<?= $col ?>-100 text-<?= $col ?>-700 px-2 py-0.5 rounded-full font-semibold"><?= count($deals_by_stage[$stageKey] ?? []) ?></span>
                    </div>
                    <div class="flex-1 p-2 space-y-2">
                        <?php foreach (($deals_by_stage[$stageKey] ?? []) as $d): ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-3 shadow-sm" data-testid="deal-card-<?= $d['id'] ?>">
                            <p class="text-sm font-semibold text-gray-900 mb-1"><?= htmlspecialchars($d['title']) ?></p>
                            <?php if ($d['lead_name'] || $d['client_company'] || $d['client_name']): ?>
                            <p class="text-xs text-gray-500 mb-1"><i class="fas fa-user text-gray-300 mr-1"></i><?= htmlspecialchars($d['client_company'] ?: $d['lead_name'] ?: '') ?></p>
                            <?php endif; ?>
                            <?php if ($d['value']): ?>
                            <p class="text-xs font-semibold text-green-700 mb-2">$<?= number_format($d['value'], 0) ?></p>
                            <?php endif; ?>
                            <div class="flex items-center justify-between">
                                <form method="POST" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_deal_stage">
                                    <input type="hidden" name="deal_id" value="<?= $d['id'] ?>">
                                    <select name="stage" onchange="this.form.submit()" class="text-[10px] border rounded px-1 py-0.5 bg-white text-gray-600" data-testid="select-deal-stage-<?= $d['id'] ?>">
                                        <?php foreach ($stages as $sk => $sl): ?>
                                            <option value="<?= $sk ?>" <?= $d['stage'] === $sk ? 'selected' : '' ?>><?= $sl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete deal?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_deal">
                                    <input type="hidden" name="deal_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="text-red-400 hover:text-red-600 text-xs ml-2" data-testid="button-delete-deal-<?= $d['id'] ?>"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="add-deal-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg w-full max-w-lg mx-4 shadow-xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <h3 class="text-lg font-semibold">Add Deal</h3>
                        <button onclick="document.getElementById('add-deal-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_deal">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Deal Title *</label>
                            <input type="text" name="deal_title" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-deal-title">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Stage</label>
                                <select name="deal_stage" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-deal-stage">
                                    <?php foreach ($stages as $sk => $sl): ?>
                                        <option value="<?= $sk ?>"><?= $sl ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Value ($)</label>
                                <input type="number" name="deal_value" min="0" step="0.01" placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-deal-value">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Lead</label>
                                <select name="deal_lead_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-deal-lead">
                                    <option value="">-- None --</option>
                                    <?php foreach ($leads as $l): ?>
                                        <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                                <select name="deal_client_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-deal-client">
                                    <option value="">-- None --</option>
                                    <?php foreach ($clients_list as $cl): ?>
                                        <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['company'] ?: $cl['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Expected Close Date</label>
                            <input type="date" name="deal_close_date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-deal-close-date">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea name="deal_notes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-deal-notes"></textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="document.getElementById('add-deal-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-deal">Create Deal</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'marketing'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900">Social Media Posts</h2>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 mb-4">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h3 class="text-sm font-semibold text-gray-900"><i class="fas fa-plus-circle text-blue-500 mr-2"></i>Schedule New Post</h3>
                        </div>
                        <form method="POST" class="p-4 space-y-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_social_post">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Platform</label>
                                    <select name="post_platform" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-post-platform">
                                        <option value="facebook"><i class="fab fa-facebook"></i> Facebook</option>
                                        <option value="instagram">Instagram</option>
                                        <option value="linkedin">LinkedIn</option>
                                        <option value="twitter">X (Twitter)</option>
                                        <option value="google_business">Google Business</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Scheduled Date/Time</label>
                                    <input type="datetime-local" name="post_scheduled_at" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-post-scheduled">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Post Content *</label>
                                <textarea name="post_content" rows="3" required placeholder="Write your social media post..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-post-content"></textarea>
                            </div>
                            <div class="flex justify-end">
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-post">
                                    <i class="fas fa-paper-plane mr-1"></i>Schedule Post
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h3 class="text-sm font-semibold text-gray-900">Scheduled &amp; Posted Content</h3>
                        </div>
                        <?php if (empty($social_posts)): ?>
                            <div class="p-6 text-center text-gray-500 text-sm">No posts scheduled yet.</div>
                        <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($social_posts as $sp):
                                $pc = ['facebook'=>'blue','instagram'=>'pink','linkedin'=>'blue','twitter'=>'sky','google_business'=>'red'][$sp['platform']] ?? 'gray';
                                $pi = ['facebook'=>'fa-facebook','instagram'=>'fa-instagram','linkedin'=>'fa-linkedin','twitter'=>'fa-twitter','google_business'=>'fa-google'][$sp['platform']] ?? 'fa-share-alt';
                            ?>
                            <div class="px-4 py-3 hover:bg-gray-50 flex items-start gap-3" data-testid="post-row-<?= $sp['id'] ?>">
                                <div class="w-8 h-8 rounded-lg bg-<?= $pc ?>-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fab <?= $pi ?> text-<?= $pc ?>-600 text-sm"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xs font-semibold text-gray-700 capitalize"><?= htmlspecialchars(str_replace('_', ' ', $sp['platform'])) ?></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full <?= $sp['status'] === 'posted' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' ?>"><?= ucfirst($sp['status']) ?></span>
                                        <?php if ($sp['scheduled_at']): ?>
                                        <span class="text-[10px] text-gray-400"><?= date('M d, g:i A', strtotime($sp['scheduled_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm text-gray-700 line-clamp-2"><?= htmlspecialchars($sp['content']) ?></p>
                                </div>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this post?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_social_post">
                                    <input type="hidden" name="post_id" value="<?= $sp['id'] ?>">
                                    <button type="submit" class="text-red-400 hover:text-red-600 text-xs flex-shrink-0" data-testid="button-delete-post-<?= $sp['id'] ?>"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Marketing Overview</h2>
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <?php
                        $total_posts = count($social_posts);
                        $posted = count(array_filter($social_posts, fn($p) => $p['status'] === 'posted'));
                        $scheduled_count = count(array_filter($social_posts, fn($p) => $p['status'] === 'scheduled'));
                        ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-semibold text-gray-500 uppercase">Total Posts</p>
                            <p class="text-2xl font-bold text-gray-900"><?= $total_posts ?></p>
                        </div>
                        <div class="bg-white rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-semibold text-gray-500 uppercase">Scheduled</p>
                            <p class="text-2xl font-bold text-blue-600"><?= $scheduled_count ?></p>
                        </div>
                        <div class="bg-white rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-semibold text-gray-500 uppercase">Posted</p>
                            <p class="text-2xl font-bold text-green-600"><?= $posted ?></p>
                        </div>
                        <div class="bg-white rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-semibold text-gray-500 uppercase">Campaigns Active</p>
                            <p class="text-2xl font-bold text-purple-600"><?= count(array_filter($campaigns, fn($c) => $c['status'] === 'active')) ?></p>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Tips</h3>
                        <ul class="text-sm text-gray-600 space-y-2">
                            <li><i class="fas fa-check-circle text-green-500 mr-2"></i>Schedule posts consistently to build audience</li>
                            <li><i class="fas fa-check-circle text-green-500 mr-2"></i>Best times: Tue-Thu, 9am–11am &amp; 1pm–3pm</li>
                            <li><i class="fas fa-check-circle text-green-500 mr-2"></i>Mix promotional and educational content (80/20 rule)</li>
                            <li><i class="fas fa-check-circle text-green-500 mr-2"></i>Use hashtags relevant to MSP and fiber services</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Social API Connections -->
            <div class="mt-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-plug text-blue-500 mr-2"></i>Platform API Connections</h2>
                </div>
                <?php
                $api_platforms = [
                    'facebook'  => ['Facebook','fa-facebook','#1877F2', ['App ID'=>'app_id','App Secret'=>'app_secret','Page Access Token'=>'access_token','Page ID'=>'page_id']],
                    'instagram' => ['Instagram','fa-instagram','#E1306C', ['Access Token'=>'access_token','Business Account ID'=>'page_id']],
                    'linkedin'  => ['LinkedIn','fa-linkedin','#0A66C2', ['Client ID'=>'app_id','Client Secret'=>'app_secret','Page Access Token'=>'access_token','Organization ID'=>'org_id']],
                    'youtube'   => ['YouTube','fa-youtube','#FF0000', ['API Key'=>'api_key','Channel ID'=>'channel_id']],
                ];
                foreach ($api_platforms as $plat => [$plat_name, $plat_icon, $plat_color, $plat_fields]):
                    $is_connected = false;
                    foreach ($plat_fields as $label => $field) {
                        if (!empty($social_api["social_{$plat}_{$field}"])) { $is_connected = true; break; }
                    }
                ?>
                <div class="bg-white rounded-xl border border-gray-200 mb-4">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="text-xl" style="color:<?= $plat_color ?>"><i class="fab <?= $plat_icon ?>"></i></span>
                            <h3 class="font-semibold text-gray-900"><?= $plat_name ?></h3>
                        </div>
                        <?php if ($is_connected): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 text-xs font-semibold rounded-full"><i class="fas fa-check-circle"></i> Connected</span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 text-gray-500 text-xs rounded-full"><i class="fas fa-circle"></i> Not connected</span>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="p-5">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_social_api">
                        <div class="grid grid-cols-2 gap-4">
                            <?php foreach ($plat_fields as $label => $field):
                                $key = "social_{$plat}_{$field}";
                                $val = $social_api[$key] ?? '';
                                $is_secret = in_array($field, ['app_secret','access_token']);
                            ?>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1"><?= $label ?></label>
                                <input type="<?= $is_secret ? 'password' : 'text' ?>"
                                    name="<?= $key ?>"
                                    value="<?= htmlspecialchars($val) ?>"
                                    placeholder="<?= $is_secret ? '••••••••' : "Enter $label..." ?>"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono"
                                    data-testid="input-<?= $key ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-save-<?= $plat ?>-api">
                                <i class="fas fa-save mr-1"></i>Save <?= $plat_name ?> Settings
                            </button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ── Social Post Publisher ──────────────────────────────── -->
            <?php
            $any_social_connected = false;
            foreach (['facebook','instagram','linkedin','youtube'] as $_sp) {
                if (!empty($social_api["social_{$_sp}_access_token"]) || !empty($social_api["social_{$_sp}_api_key"])) { $any_social_connected = true; break; }
            }
            ?>
            <div class="mt-6 bg-white rounded-xl border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-paper-plane text-blue-500 mr-2"></i>Social Post Publisher</h2>
                    <?php if (!$any_social_connected): ?><span class="text-sm text-gray-400">Configure API credentials above to enable posting</span><?php endif; ?>
                </div>
                <div class="p-6">
                    <form method="POST" id="social-post-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="post_to_platform">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 uppercase mb-2">Platform</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <?php
                                    $pub_platforms = [
                                        'facebook'  => ['Facebook','fa-facebook','#1877F2', !empty($social_api['social_facebook_access_token'])],
                                        'instagram' => ['Instagram','fa-instagram','#E1306C', !empty($social_api['social_instagram_access_token'])],
                                        'linkedin'  => ['LinkedIn','fa-linkedin','#0A66C2', !empty($social_api['social_linkedin_access_token'])],
                                        'youtube'   => ['YouTube','fa-youtube','#FF0000', !empty($social_api['social_youtube_api_key'])],
                                    ];
                                    foreach ($pub_platforms as $pp => [$pname, $pico, $pcol, $pconn]): ?>
                                    <label class="flex items-center gap-2 p-3 border rounded-lg cursor-pointer hover:bg-gray-50 transition <?= !$pconn ? 'opacity-50' : '' ?>">
                                        <input type="radio" name="post_platform" value="<?= $pp ?>" <?= !$pconn ? 'disabled' : '' ?> class="text-blue-600">
                                        <span style="color:<?= $pcol ?>"><i class="fab <?= $pico ?>"></i></span>
                                        <span class="text-sm font-medium"><?= $pname ?></span>
                                        <?php if ($pconn): ?><span class="ml-auto text-xs text-green-600"><i class="fas fa-check-circle"></i></span><?php else: ?><span class="ml-auto text-xs text-gray-400">No key</span><?php endif; ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="flex flex-col gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Post Content *</label>
                                    <textarea name="post_content" rows="4" placeholder="Write your post content here…" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" required></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Image URL (optional — required for Instagram)</label>
                                    <input type="url" name="post_image_url" placeholder="https://example.com/image.jpg" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                            <p class="text-xs text-gray-400"><i class="fas fa-info-circle mr-1"></i>YouTube requires video upload via YouTube Studio. Select YouTube to get the link.</p>
                            <div class="flex gap-3">
                                <button type="button" onclick="saveDraftPost()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50">
                                    <i class="fas fa-save mr-1"></i>Save Draft
                                </button>
                                <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" <?= !$any_social_connected ? 'title="Configure credentials first"' : '' ?>>
                                    <i class="fas fa-paper-plane mr-1"></i>Post Now
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Social Posts Log -->
            <?php if (!empty($social_posts)): ?>
            <div class="mt-4 bg-white rounded-xl border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900"><i class="fas fa-history text-gray-400 mr-2"></i>Recent Posts</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php foreach (array_slice($social_posts, 0, 5) as $sp):
                        $spic = ['facebook'=>'fa-facebook text-blue-600','instagram'=>'fa-instagram text-pink-500','linkedin'=>'fa-linkedin text-blue-700','youtube'=>'fa-youtube text-red-500','twitter'=>'fa-twitter text-sky-500'][$sp['platform']] ?? 'fa-share-alt text-gray-400';
                    ?>
                    <div class="px-6 py-3 flex items-start gap-3">
                        <i class="fab <?= $spic ?> mt-0.5"></i>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-800 truncate"><?= htmlspecialchars($sp['content']) ?></p>
                            <p class="text-xs text-gray-400 mt-0.5"><?= date('M d, Y g:i a', strtotime($sp['created_at'])) ?> · <?= ucfirst($sp['status']) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>

            <?php if ($tab === 'campaigns'): ?>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Campaigns</h2>
                <button onclick="document.getElementById('add-campaign-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-campaign">
                    <i class="fas fa-plus mr-1"></i>Create Campaign
                </button>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <?php if (empty($campaigns)): ?>
                    <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-bullhorn text-gray-300 text-2xl mb-2 block"></i>No campaigns yet.</div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-campaigns">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Target</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Dates</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Stats</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($campaigns as $c):
                                $sc = ['draft'=>'gray','active'=>'green','paused'=>'yellow','completed'=>'blue','cancelled'=>'red'][$c['status']] ?? 'gray';
                                $tc = ['email'=>'blue','sms'=>'green','call'=>'yellow','social'=>'purple'][$c['type']] ?? 'gray';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="campaign-row-<?= $c['id'] ?>">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($c['name']) ?></td>
                                <td class="px-4 py-3"><span class="text-xs bg-<?= $tc ?>-100 text-<?= $tc ?>-700 px-2 py-0.5 rounded"><?= ucfirst($c['type']) ?></span></td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_campaign_status">
                                        <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="text-xs border rounded px-2 py-1 bg-<?= $sc ?>-50 text-<?= $sc ?>-700 border-<?= $sc ?>-200">
                                            <?php foreach (['draft','active','paused','completed','cancelled'] as $s): ?>
                                                <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600"><?= str_replace('_', ' ', ucfirst($c['target_audience'])) ?></td>
                                <td class="px-4 py-3 text-xs text-gray-500"><?= $c['start_date'] ? date('M d', strtotime($c['start_date'])) : 'N/A' ?><?= $c['end_date'] ? ' - ' . date('M d', strtotime($c['end_date'])) : '' ?></td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    <span title="Sent"><?= (int)$c['sent_count'] ?> sent</span> /
                                    <span title="Opened"><?= (int)$c['open_count'] ?> opened</span>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this campaign?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_campaign">
                                        <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm" data-testid="button-delete-campaign-<?= $c['id'] ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div id="add-campaign-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg w-full max-w-lg mx-4 shadow-xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <h3 class="text-lg font-semibold">Create Campaign</h3>
                        <button onclick="document.getElementById('add-campaign-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_campaign">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Campaign Name *</label>
                            <input type="text" name="campaign_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-campaign-name">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="campaign_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-campaign-type">
                                    <option value="email">Email</option>
                                    <option value="sms">SMS</option>
                                    <option value="call">Call</option>
                                    <option value="social">Social Media</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Target Audience</label>
                                <select name="campaign_target" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-campaign-target">
                                    <option value="all_clients">All Clients</option>
                                    <option value="active_clients">Active Clients</option>
                                    <option value="leads">Leads</option>
                                    <option value="inactive_clients">Inactive Clients</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                            <input type="text" name="campaign_subject" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-campaign-subject">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                            <textarea name="campaign_content" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-campaign-content"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                <input type="date" name="campaign_start" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-campaign-start">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                <input type="date" name="campaign_end" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-campaign-end">
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="document.getElementById('add-campaign-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-campaign">Create Campaign</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'meetings'): ?>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Meetings & Schedule</h2>
                <button onclick="document.getElementById('add-meeting-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-meeting">
                    <i class="fas fa-plus mr-1"></i>Schedule Meeting
                </button>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <?php if (empty($meetings)): ?>
                    <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-calendar-alt text-gray-300 text-2xl mb-2 block"></i>No meetings scheduled.</div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-meetings">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Title</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date & Time</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Duration</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($meetings as $m):
                                $mc = ['scheduled'=>'blue','completed'=>'green','cancelled'=>'red','no_show'=>'yellow'][$m['status']] ?? 'gray';
                                $tc = ['consultation'=>'blue','onboarding'=>'green','support'=>'yellow','review'=>'purple','sales'=>'indigo'][$m['meeting_type']] ?? 'gray';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="meeting-row-<?= $m['id'] ?>">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($m['title']) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($m['client_name'] ?? 'N/A') ?></td>
                                <td class="px-4 py-3"><span class="text-xs bg-<?= $tc ?>-100 text-<?= $tc ?>-700 px-2 py-0.5 rounded"><?= ucfirst($m['meeting_type']) ?></span></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= date('M d, Y g:i A', strtotime($m['scheduled_at'])) ?></td>
                                <td class="px-4 py-3 text-xs text-gray-500"><?= (int)$m['duration_minutes'] ?> min</td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_meeting_status">
                                        <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="text-xs border rounded px-2 py-1 bg-<?= $mc ?>-50 text-<?= $mc ?>-700 border-<?= $mc ?>-200">
                                            <?php foreach (['scheduled','completed','cancelled','no_show'] as $s): ?>
                                                <option value="<?= $s ?>" <?= $m['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this meeting?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_meeting">
                                        <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm" data-testid="button-delete-meeting-<?= $m['id'] ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div id="add-meeting-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg w-full max-w-lg mx-4 shadow-xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <h3 class="text-lg font-semibold">Schedule Meeting</h3>
                        <button onclick="document.getElementById('add-meeting-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_meeting">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                            <input type="text" name="meeting_title" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-title">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                                <select name="meeting_client_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-meeting-client">
                                    <option value="">-- Select Client --</option>
                                    <?php foreach ($clients_list as $cl): ?>
                                        <option value="<?= $cl['id'] ?>"><?= htmlspecialchars(($cl['company'] ?: $cl['name'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="meeting_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-meeting-type">
                                    <option value="consultation">Consultation</option>
                                    <option value="onboarding">Onboarding</option>
                                    <option value="support">Support</option>
                                    <option value="review">Review</option>
                                    <option value="sales">Sales</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date *</label>
                                <input type="date" name="meeting_date" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-date">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Time</label>
                                <input type="time" name="meeting_time" value="09:00" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-time">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Duration</label>
                                <select name="meeting_duration" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-meeting-duration">
                                    <option value="30">30 min</option>
                                    <option value="60" selected>1 hour</option>
                                    <option value="90">1.5 hours</option>
                                    <option value="120">2 hours</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                                <input type="text" name="meeting_location" placeholder="Zoom, Office, Phone..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-location">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-cloud text-blue-400 mr-1"></i>Nextcloud Calendar Link</label>
                                <input type="url" name="meeting_calendar_link" placeholder="https://cloud.example.com/..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-calendar-link">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea name="meeting_notes" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-meeting-notes"></textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="document.getElementById('add-meeting-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-meeting">Schedule Meeting</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'inbox'): ?>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Inbox</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-ticket-alt text-blue-500 mr-2"></i>Recent Tickets</h3>
                    </div>
                    <?php if (empty($recent_tickets)): ?>
                        <div class="p-6 text-center text-gray-500 text-sm">No recent tickets.</div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($recent_tickets as $t):
                            $sc = ['open'=>'green','in_progress'=>'blue','closed'=>'gray'][$t['status']] ?? 'gray';
                            $pc = ['low'=>'gray','medium'=>'yellow','high'=>'red','critical'=>'red'][$t['priority']] ?? 'gray';
                        ?>
                        <a href="admin-ticket-detail.php?id=<?= $t['id'] ?>" class="block px-6 py-3 hover:bg-gray-50 transition" data-testid="inbox-ticket-<?= $t['id'] ?>">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($t['subject']) ?></p>
                                <span class="text-xs bg-<?= $sc ?>-100 text-<?= $sc ?>-700 px-2 py-0.5 rounded ml-2 flex-shrink-0"><?= ucfirst(str_replace('_',' ',$t['status'])) ?></span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($t['client_name'] ?? 'Unknown') ?> &middot; <?= date('M d, g:i A', strtotime($t['created_at'])) ?></p>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-comments text-blue-500 mr-2"></i>Recent Chat Messages</h3>
                    </div>
                    <?php if (empty($recent_messages)): ?>
                        <div class="p-6 text-center text-gray-500 text-sm">No recent messages.</div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($recent_messages as $msg): ?>
                        <div class="px-6 py-3 hover:bg-gray-50" data-testid="inbox-message-<?= $msg['id'] ?>">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($msg['user_name'] ?? 'Unknown') ?></p>
                                <span class="text-xs text-gray-400">#<?= htmlspecialchars($msg['channel_name'] ?? $msg['room'] ?? '') ?></span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1 truncate"><?= htmlspecialchars(substr($msg['message'] ?? '', 0, 100)) ?></p>
                            <p class="text-xs text-gray-400 mt-1"><?= date('M d, g:i A', strtotime($msg['created_at'])) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// Lead Communication Tab Switcher
function switchLeadCommTab(type) {
    document.getElementById('lead-comm-type-val').value = type;
    document.querySelectorAll('.lead-comm-tab').forEach(btn => {
        const active = btn.dataset.tab === type;
        btn.classList.toggle('bg-white', active);
        btn.classList.toggle('border-blue-300', active);
        btn.classList.toggle('text-blue-700', active);
        btn.classList.toggle('font-medium', active);
        btn.classList.toggle('shadow-sm', active);
        btn.classList.toggle('border-transparent', !active);
        btn.classList.toggle('text-gray-500', !active);
    });
    // Show/hide extra fields
    document.querySelectorAll('.lead-comm-call-field').forEach(el => el.classList.toggle('hidden', type !== 'call'));
    document.querySelectorAll('.lead-comm-meeting-field').forEach(el => el.classList.toggle('hidden', type !== 'meeting'));
    // Update hints
    const hints = {note:'Log a note about this lead',email:'Send an email to this lead',call:'Log a call with outcome',meeting:'Schedule or log a meeting'};
    const btns  = {note:'Save Note',email:'Send Email',call:'Log Call',meeting:'Log Meeting'};
    const hintEl = document.getElementById('lead-comm-hint');
    const btnEl  = document.getElementById('lead-comm-btn-text');
    if (hintEl) hintEl.textContent = hints[type] || '';
    if (btnEl)  btnEl.textContent  = btns[type]  || 'Save';
}

// Save draft post to scheduled queue
function saveDraftPost() {
    const form = document.getElementById('social-post-form');
    const content = form.querySelector('[name=post_content]').value;
    const platform = form.querySelector('[name=post_platform]:checked')?.value || '';
    if (!content) { alert('Please enter post content first.'); return; }
    const f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = `<input name="action" value="add_social_post"><input name="post_platform" value="${platform}"><input name="post_content" value="${content.replace(/"/g,'&quot;')}"><input name="_csrf_token" value="${document.querySelector('[name=_csrf_token]')?.value || ''}">`;
    document.body.appendChild(f); f.submit();
}
</script>
</body>
</html>
