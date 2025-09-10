<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -------------------- CONFIG --------------------
define('BOT_TOKEN', '8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU');
define('CHANNEL_ID', '@EntertainmentTadka786');
define('GROUP_CHANNEL_ID', '@EntertainmentTadka0786');
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('BACKUP_DIR', 'backups/');
define('CACHE_EXPIRY', 300);
define('ITEMS_PER_PAGE', 5);
// ------------------------------------------------

if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode([
        'users' => [], 
        'total_requests' => 0,
        'message_logs' => []  // NEW: Message tracking
    ]));
    @chmod(USERS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "movie_name,message_id,date\n");
    @chmod(CSV_FILE, 0666);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0,
        'total_users' => 0,
        'total_seaches' => 0,
        'last_updated' => date('Y-m-d H:i:s')
    ]));
    @chmod(STATS_FILE, 0666);
}

if (!file_exists(BACKUP_DIR)) {
    @mkdir(BACKUP_DIR, 0777, true);
}

// memory caches
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();

// ==============================
// Time Check Function - NEW WITH SUNDAY OFF
// ==============================
function is_group_active_time() {
    $current_day = date('w'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
    $current_time = time();
    $current_hour = (int)date('H');
    $current_minute = (int)date('i');
    
    // ✅ SUNDAY COMPLETELY OFF
    if ($current_day == 0) { // 0 = Sunday
        return false;
    }
    
    // Monday-Saturday: 10:00 AM to 6:30 PM
    $start_time = 10;   // 10 AM
    $end_time = 18;     // 6 PM
    $end_minute = 30;   // 30 minutes
    
    // Check if current time is between 10 AM and 6:30 PM
    if ($current_hour > $start_time && $current_hour < $end_time) {
        return true;
    }
    
    // Check 10:00 AM exactly
    if ($current_hour == $start_time && $current_minute >= 0) {
        return true;
    }
    
    // Check 6:30 PM exactly
    if ($current_hour == $end_time && $current_minute <= $end_minute) {
        return true;
    }
    
    return false;
}

// ==============================
// Auto Group Message - NEW WITH SUNDAY
// ==============================
function send_group_opening_message() {
    if (!defined('GROUP_CHANNEL_ID')) return;
    
    $current_day = date('w');
    
    // Sunday ko message mat bhejo
    if ($current_day == 0) return;
    
    if (date('H:i') == '10:00') {
        $message = "🌟 <b>Group is now OPEN!</b>\n\n";
        $message .= "🕙 <b>Today's Timing:</b> 10:00 AM to 6:30 PM\n";
        $message .= "🚫 <b>Sunday Closed:</b> Full day off\n";
        $message .= "🎬 <b>Request movies here:</b> @EntertainmentTadka0786\n";
        $message .= "📢 <b>Main channel:</b> @EntertainmentTadka786\n\n";
        $message .= "⚠️ Group will close at 6:30 PM automatically";
        
        sendMessage(GROUP_CHANNEL_ID, $message, null, 'HTML');
    }
}

function send_group_closing_message() {
    if (!defined('GROUP_CHANNEL_ID')) return;
    
    $current_day = date('w');
    
    // Sunday ko message mat bhejo
    if ($current_day == 0) return;
    
    if (date('H:i') == '18:30') {
        $message = "⏰ <b>Group is now CLOSED!</b>\n\n";
        
        // Kal Sunday hai ya nahi?
        $tomorrow_day = date('w', strtotime('+1 day'));
        if ($tomorrow_day == 0) {
            $message .= "🚫 <b>Tomorrow Sunday:</b> Full day closed\n";
        } else {
            $message .= "🕙 <b>Will open tomorrow at:</b> 10:00 AM\n";
        }
        
        $message .= "🎬 <b>You can still use bot:</b> @EntertainmentTadkaBot\n";
        $message .= "📢 <b>Main channel:</b> @EntertainmentTadka786\n\n";
        $message .= "😴 Goodnight! See you tomorrow!";
        
        sendMessage(GROUP_CHANNEL_ID, $message, null, 'HTML');
    }
}

function send_sunday_status_message() {
    if (!defined('GROUP_CHANNEL_ID')) return;
    
    if (date('w') == 0 && date('H:i') == '10:00') {
        $message = "🚫 <b>SUNDAY CLOSED NOTICE</b>\n\n";
        $message .= "📅 Aaj Sunday hai - Group complete day off hai\n\n";
        $message .= "🕙 <b>Regular Timing:</b>\n";
        $message .= "• Monday-Saturday: 10:00 AM to 6:30 PM\n";
        $message .= "• Sunday: Closed\n\n";
        $message .= "🎬 Bot available hai: @EntertainmentTadkaBot\n";
        $message .= "📢 Movies channel: @EntertainmentTadka786\n\n";
        $message .= "😊 Enjoy your Sunday!";
        
        sendMessage(GROUP_CHANNEL_ID, $message, null, 'HTML');
    }
}

// ==============================
// Stats
// ==============================
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

// ==============================
// Caching / CSV loading
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date\n");
        return [];
    }

    $data = [];
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && (!empty(trim($row[0])))) {
                $movie_name = trim($row[0]);
                $message_id_raw = isset($row[1]) ? trim($row[1]) : '';
                $date = isset($row[2]) ? trim($row[2]) : '';
                $video_path = isset($row[3]) ? trim($row[3]) : '';

                $entry = [
                    'movie_name' => $movie_name,
                    'message_id_raw' => $message_id_raw,
                    'date' => $date,
                    'video_path' => $video_path
                ];
                if (is_numeric($message_id_raw)) {
                    $entry['message_id'] = intval($message_id_raw);
                } else {
                    $entry['message_id'] = null;
                }

                $data[] = $entry;

                $movie = strtolower($movie_name);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                $movie_messages[$movie][] = $entry;
            }
        }
        fclose($handle);
    }

    // Update stats
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats['total_movies'] = count($data);
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));

    // Re-write CSV
    $handle = fopen($filename, "w");
    fputcsv($handle, array('movie_name','message_id','date','video_path'));
    foreach ($data as $row) {
        fputcsv($handle, [$row['movie_name'], $row['message_id_raw'], $row['date'], $row['video_path']]);
    }
    fclose($handle);

    error_log("✅ CSV loaded and cleaned - " . count($data) . " movies");
    return $data;
}

function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    $movie_cache = [
        'data' => load_and_clean_csv(),
        'timestamp' => time()
    ];
    return $movie_cache['data'];
}

function load_movies_from_csv() {
    return get_cached_movies();
}

// ==============================
// Telegram API helpers
// ==============================
function apiRequest($method, $params = array(), $is_multipart = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    if ($is_multipart) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        if ($res === false) {
            error_log("CURL ERROR: " . curl_error($ch));
        }
        curl_close($ch);
    } else {
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => http_build_query($params),
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
            )
        );
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            error_log("apiRequest failed for method $method");
        }
        return $result;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    apiRequest('sendMessage', $data);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    apiRequest('answerCallbackQuery', $data);
}

// ==============================
// DELIVERY LOGIC - SINGLE CHANNEL
// ==============================
function deliver_item_to_chat($chat_id, $item) {
    // If numeric message_id -> forward to SINGLE CHANNEL
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        // Sirf ek channel mein forward
        forwardMessage($chat_id, CHANNEL_ID, $item['message_id']);
        return true;
    }

    // Fallback: send text info
    $text = "🎬 " . ($item['movie_name'] ?? 'Unknown') . "\n";
    $text .= "Ref: " . ($item['message_id_raw'] ?? 'N/A') . "\n";
    $text .= "Date: " . ($item['date'] ?? 'N/A') . "\n";
    sendMessage($chat_id, $text, null, 'HTML');
    return false;
}

// ==============================
// Pagination helpers
// ==============================
function get_all_movies_list() {
    $all = get_cached_movies();
    return $all;  // ASCENDING ORDER (A-Z) KE LIYE
}

function paginate_movies(array $all, int $page): array {
    $total = count($all);
    if ($total === 0) return ['total'=>0,'total_pages'=>1,'page'=>1,'slice'=>[]];
    $total_pages = (int)ceil($total / ITEMS_PER_PAGE);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * ITEMS_PER_PAGE;
    return [
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page,
        'slice' => array_slice($all, $start, ITEMS_PER_PAGE)
    ];
}

function forward_page_movies($chat_id, array $page_movies) {
    $i = 1;
    foreach ($page_movies as $m) {
        $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        deliver_item_to_chat($chat_id, $m);
        usleep(300000);
        $i++;
    }
}

function build_totalupload_keyboard(int $page, int $total_pages): array {
    $kb = ['inline_keyboard' => []];
    $row = [];
    if ($page > 1) $row[] = ['text'=>'⏮️ Previous','callback_data'=>'tu_prev_'.($page-1)];
    if ($page < $total_pages) $row[] = ['text'=>'⏭️ Next','callback_data'=>'tu_next_'.($page+1)];
    if (!empty($row)) $kb['inline_keyboard'][] = $row;
    $kb['inline_keyboard'][] = [
        ['text'=>'🎬 View Movie','callback_data'=>'tu_view_'.$page],
        ['text'=>'🛑 Stop','callback_data'=>'tu_stop']
    ];
    return $kb;
}

// ==============================
// /totalupload controller
// ==============================
function totalupload_controller($chat_id, $page = 1) {
    $all = get_all_movies_list();
    if (empty($all)) {
        sendMessage($chat_id, "⚠️ Abhi tak koi movie record nahi mila. Baad me try karein.");
        return;
    }
    $pg = paginate_movies($all, (int)$page);
    forward_page_movies($chat_id, $pg['slice']);

    $title = "📊 <b>Total Uploads</b>\n";
    $title .= "• Page {$pg['page']}/{$pg['total_pages']}\n";
    $title .= "• Showing: " . count($pg['slice']) . " of {$pg['total']}\n\n";
    $title .= "➡️ Navigate with buttons below or tap <b>View Movie</b> to re-send current page.";

    $kb = build_totalupload_keyboard($pg['page'], $pg['total_pages']);
    sendMessage($chat_id, $title, $kb, 'HTML');
}

// ==============================
// Append movie
// ==============================
function append_movie($movie_name, $message_id_raw, $date = null, $video_path = '') {
    if (empty(trim($movie_name))) return;
    if ($date === null) $date = date('d-m-Y');
    $entry = [$movie_name, $message_id_raw, $date, $video_path];
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $entry);
    fclose($handle);

    global $movie_messages, $movie_cache, $waiting_users;
    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id_raw' => $message_id_raw,
        'date' => $date,
        'video_path' => $video_path,
        'message_id' => is_numeric($message_id_raw) ? intval($message_id_raw) : null
    ];
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = [];

    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                deliver_item_to_chat($user_chat_id, $item);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
        }
    }

    update_stats('total_movies', 1);
}

// ==============================
// Search & language & points
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        if ($movie == $query_lower) $score = 100;
        elseif (strpos($movie, $query_lower) !== false) $score = 80 - (strlen($movie) - strlen($query_lower));
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) $score = $similarity;
        }
        if ($score > 0) $results[$movie] = ['score'=>$score,'count'=>count($entries)];
    }
    uasort($results, function($a,$b){return $b['score'] - $a['score'];});
    return array_slice($results,0,10);
}

function detect_language($text) {
    $hindi_keywords = ['फिल्म','मूवी','डाउनलोड','हिंदी'];
    $english_keywords = ['movie','download','watch','print'];
    $h=0;$e=0;
    foreach ($hindi_keywords as $k) if (strpos($text,$k)!==false) $h++;
    foreach ($english_keywords as $k) if (stripos($text,$k)!==false) $e++;
    return $h>$e ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    // HINGLISH RESPONSES - UPDATED
    $responses = [
        'hindi'=>[
            'welcome' => "🎬 Boss, kis movie ki talash hai?",
            'found' => "✅ Mil gayi! Movie forward ho rahi hai...",
            'not_found' => "😔 Yeh movie abhi available nahi hai!\n\n📝 Aap ise request kar sakte hain: @EntertainmentTadka0786\n\n🔔 Jab bhi yeh add hogi, main automatically bhej dunga!",
            'searching' => "🔍 Dhoondh raha hoon... Zara wait karo"
        ],
        'english'=>[
            'welcome' => "🎬 Boss, which movie are you looking for?",
            'found' => "✅ Found it! Forwarding the movie...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: @EntertainmentTadka0786\n\n🔔 I'll send it automatically once it's added!",
            'searching' => "🔍 Searching... Please wait"
        ]
    ];
    sendMessage($chat_id, $responses[$language][$message_type]);
}

function update_user_points($user_id, $action) {
    $points_map = ['search'=>1,'found_movie'=>5,'daily_login'=>10];
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (!isset($users_data['users'][$user_id]['points'])) $users_data['users'][$user_id]['points'] = 0;
    $users_data['users'][$user_id]['points'] += ($points_map[$action] ?? 0);
    $users_data['users'][$user_id]['last_activity'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
}

function advanced_search($chat_id, $query, $user_id = null) {
    global $movie_messages, $waiting_users;
    $q = strtolower(trim($query));
    
    // ✅ Check if query looks like a movie name
    if (strlen($q) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
        return;
    }
    
    // ✅ Filter out non-movie queries
    $invalid_keywords = ['vlc', 'audio', 'track', 'change', 'open', 'kar', 'me', 'hai', 'how', 'what', 'problem', 'issue', 'help'];
    $query_words = explode(' ', $q);
    
    $invalid_count = 0;
    foreach ($query_words as $word) {
        if (in_array($word, $invalid_keywords)) {
            $invalid_count++;
        }
    }
    
    // Agar 50% se zyada words invalid hain toh
    if ($invalid_count > 0 && ($invalid_count / count($query_words)) > 0.5) {
        $help_msg = "🎬 <b>Please enter a movie name!</b>\n\n";
        $help_msg .= "🔍 <b>Examples of movie names:</b>\n";
        $help_msg .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n• spider-man\n\n";
        $help_msg .= "❌ <i>Technical queries like 'vlc', 'audio track', etc. are not movie names.</i>\n\n";
        $help_msg .= "📢 Join: @EntertainmentTadka786\n";
        $help_msg .= "💬 Help: @EntertainmentTadka0786";
        sendMessage($chat_id, $help_msg, null, 'HTML');
        return;
    }
    
    $found = smart_search($q);
    if (!empty($found)) {
        $msg = "🔍 Found " . count($found) . " movies for '$query':\n\n";
        $i=1;
        foreach ($found as $movie=>$data) {
            $msg .= "$i. $movie (" . $data['count'] . " entries)\n";
            $i++; if ($i>15) break;
        }
        sendMessage($chat_id, $msg);
        $keyboard = ['inline_keyboard'=>[]];
        foreach (array_slice(array_keys($found),0,5) as $movie) {
            $keyboard['inline_keyboard'][] = [[ 'text'=>"🎬 ".ucwords($movie), 'callback_data'=>$movie ]];
        }
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
        if ($user_id) update_user_points($user_id, 'found_movie');
    } else {
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $lang);
        if (!isset($waiting_users[$q])) $waiting_users[$q] = [];
        $waiting_users[$q][] = [$chat_id, $user_id ?? $chat_id];
    }
    update_stats('total_searches', 1);
    if ($user_id) update_user_points($user_id, 'search');
}

// ==============================
// Admin stats
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $msg = "📊 <b>Bot Statistics</b>\n\n";
    $msg .= "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg .= "👥 Total Users: " . $total_users . "\n";
    $msg .= "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $msg .= "🕒 Last Updated: " . ($stats['last_updated'] ?? 'N/A') . "\n\n";
    $csv_data = load_and_clean_csv();
    $recent = array_slice($csv_data, -5);
    $msg .= "📈 <b>Recent Uploads:</b>\n";
    foreach ($recent as $r) $msg .= "• " . $r['movie_name'] . " (" . $r['date'] . ")\n";
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// Show CSV Data - NEW FUNCTION
// ==============================
function show_csv_data($chat_id, $show_all = false) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle === FALSE) {
        sendMessage($chat_id, "❌ Error opening CSV file.");
        return;
    }
    
    // Skip header
    fgetcsv($handle);
    
    $movies = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $movies[] = $row;
        }
    }
    fclose($handle);
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 CSV file is empty.");
        return;
    }
    
    // Reverse array to show latest first
    $movies = array_reverse($movies);
    
    $limit = $show_all ? count($movies) : 10;
    $movies = array_slice($movies, 0, $limit);
    
    $message = "📊 <b>CSV Movie Database</b>\n\n";
    $message .= "📁 <b>Total Movies:</b> " . count($movies) . "\n";
    if (!$show_all) {
        $message .= "🔍 <i>Showing latest 10 entries</i>\n";
        $message .= "📋 <i>Use '/checkcsv all' for full list</i>\n\n";
    } else {
        $message .= "📋 <i>Full database listing</i>\n\n";
    }
    
    $i = 1;
    foreach ($movies as $movie) {
        $movie_name = $movie[0] ?? 'N/A';
        $message_id = $movie[1] ?? 'N/A';
        $date = $movie[2] ?? 'N/A';
        
        $message .= "<b>$i.</b> 🎬 <code>" . htmlspecialchars($movie_name) . "</code>\n";
        $message .= "   📝 ID: <code>$message_id</code>\n";
        $message .= "   📅 Date: <code>$date</code>\n\n";
        
        $i++;
        
        // Message too long hone par break karo
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 <b>Continuing...</b>\n\n";
        }
    }
    
    $message .= "💾 <b>File:</b> <code>" . CSV_FILE . "</code>\n";
    $message .= "⏰ <b>Last Updated:</b> " . date('Y-m-d H:i:s', filemtime(CSV_FILE));
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// Backups & daily digest
// ==============================
function auto_backup() {
    $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    if (!file_exists($backup_dir)) mkdir($backup_dir, 0777, true);
    foreach ($backup_files as $f) if (file_exists($f)) copy($f, $backup_dir . '/' . basename($f) . '.bak');
    $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old) > 7) {
        usort($old, function($a,$b){return filemtime($a)-filemtime($b);});
        foreach (array_slice($old, 0, count($old)-7) as $d) {
            $files = glob($d . '/*'); foreach ($files as $ff) @unlink($ff); @rmdir($d);
        }
    }
}

function send_daily_digest() {
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $y_movies = [];
    $h = fopen(CSV_FILE, "r");
    if ($h !== FALSE) {
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== FALSE) {
            if (count($r)>=3 && $r[2] == $yesterday) $y_movies[] = $r[0];
        }
        fclose($h);
    }
    if (!empty($y_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $uid => $ud) {
            $msg = "📅 <b>Daily Movie Digest</b>\n\n";
            $msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
            $msg .= "🎬 Yesterday's Uploads (" . $yesterday . "):\n";
            foreach (array_slice($y_movies,0,10) as $m) $msg .= "• " . $m . "\n";
            if (count($y_movies)>10) $msg .= "• ... and " . (count($y_movies)-10) . " more\n";
            $msg .= "\n🔥 Total: " . count($y_movies) . " movies";
            sendMessage($uid, $msg, null, 'HTML');
        }
    }
}

// ==============================
// Other commands
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua."); return; }
    $date_counts = [];
    $h=fopen(CSV_FILE,'r'); if ($h!==FALSE) {
        fgetcsv($h);
        while (($r=fgetcsv($h))!==FALSE) if (count($r)>=3) { $d=$r[2]; if(!isset($date_counts[$d])) $date_counts[$d]=0; $date_counts[$d]++; }
        fclose($h);
    }
    krsort($date_counts);
    $msg = "📅 <b>Movies Upload Record</b>\n\n";
    $total_days=0; $total_movies=0;
    foreach ($date_counts as $date=>$count) { $msg .= "➡️ $date: $count movies\n"; $total_days++; $total_movies += $count; }
    $msg .= "\n📊 <b>Summary:</b>\n";
    $msg .= "• Total Days: $total_days\n• Total Movies: $total_movies\n• Average per day: " . round($total_movies / max(1,$total_days),2);
    sendMessage($chat_id,$msg,null,'HTML');
}

function total_uploads($chat_id, $page = 1) {
    totalupload_controller($chat_id, $page);
}

function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id,"⚠️ CSV file not found."); return; }
    $h = fopen(CSV_FILE,'r');
    if ($h!==FALSE) {
        fgetcsv($h);
        $i=1; $msg="";
        while (($r=fgetcsv($h))!==FALSE) {
            if (count($r)>=3) {
                $line = "$i. {$r[0]} | ID/Ref: {$r[1]} | Date: {$r[2]}\n";
                if (strlen($msg) + strlen($line) > 4000) { sendMessage($chat_id,$msg); $msg=""; }
                $msg .= $line; $i++;
            }
        }
        fclose($h);
        if (!empty($msg)) sendMessage($chat_id,$msg);
    }
}

// ==============================
// Main update processing (webhook)
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    get_cached_movies();

    // ✅ IMPROVED CHANNEL POST HANDLING - AUTOMATIC CSV SAVE
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $chat_id = $message['chat']['id']; // This is your channel's ID

        // Only process messages from your specific channel
        if ($chat_id == CHANNEL_ID) {
            $text = '';

            // 1. First, try to get the caption (for photos, videos, documents)
            if (isset($message['caption'])) {
                $text = $message['caption'];
            }
            // 2. If no caption, try to get the text
            elseif (isset($message['text'])) {
                $text = $message['text'];
            }
            // 3. If it's a document, use the file name
            elseif (isset($message['document'])) {
                $text = $message['document']['file_name'];
            }
            // 4. If it's media without caption, use a placeholder
            else {
                $text = 'Uploaded Media - ' . date('d-m-Y H:i');
            }

            // Finally, save it to the CSV
            if (!empty(trim($text))) {
                append_movie($text, $message_id, date('d-m-Y'), '');
                // Optional: Log success
                error_log("✅ Channel Post Saved: " . $text);
            }
        }
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';

        // ✅ NEW: SUNDAY COMPLETE BLOCK
        if (date('w') == 0) { // Sunday
            if ($chat_id == GROUP_CHANNEL_ID) {
                sendMessage($chat_id, "🚫 <b>SUNDAY CLOSED!</b>\n\n📅 Aaj Sunday hai - Group complete day off hai\n\n🕙 Monday se fir open hoga: 10:00 AM to 6:30 PM\n\n📢 Join: @EntertainmentTadka786");
                exit;
            }
        }
        
        // ✅ Regular time check (Monday-Saturday)
        if ($chat_id == GROUP_CHANNEL_ID) {
            if (!is_group_active_time()) {
                $current_time = date('h:i A');
                $current_day = date('l');
                
                $message = "⏰ <b>Group is closed now!</b>\n\n";
                $message .= "🕙 <b>Opening Hours:</b>\n";
                $message .= "• Monday-Saturday: 10:00 AM to 6:30 PM\n";
                $message .= "• Sunday: Closed\n\n";
                $message .= "📅 Today: $current_day\n";
                $message .= "⏰ Current time: $current_time";
                
                sendMessage($chat_id, $message, null, 'HTML');
                exit;
            }
        }

        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s'),
                'points' => 0
            ];
                        $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        }
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));

        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = $parts[0];
            if ($command == '/checkdate') check_date($chat_id);
            elseif ($command == '/totalupload' || $command == '/totaluploads' || $command == '/TOTALUPLOAD') totalupload_controller($chat_id, 1);
            elseif ($command == '/testcsv') test_csv($chat_id);
            // /checkcsv command - NEW
            elseif ($command == '/checkcsv') {
                $show_all = (isset($parts[1]) && strtolower($parts[1]) == 'all');
                show_csv_data($chat_id, $show_all);
            }
            elseif ($command == '/start') {
                $welcome = "🎬 <b>Welcome to Entertainment Tadka!</b>\n\n";
                $welcome .= "📢 <b>How to use this bot:</b>\n";
                $welcome .= "• Simply type any movie name\n";
                $welcome .= "• Use English or Hindi\n";
                $welcome .= "• Partial names also work\n\n";
                $welcome .= "🔍 <b>Examples:</b>\n";
                $welcome .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n• spider-man\n\n";
                $welcome .= "❌ <b>Don't type:</b>\n";
                $welcome .= "• Technical questions\n• Player instructions\n• Non-movie queries\n\n";
                $welcome .= "📢 Join: @EntertainmentTadka786\n";
                $welcome .= "💬 Request/Help: @EntertainmentTadka0786";
                sendMessage($chat_id, $welcome, null, 'HTML');
                update_user_points($user_id, 'daily_login');
            }
            elseif ($command == '/stats' && $user_id == 1080317415) admin_stats($chat_id);
            elseif ($command == '/help') {
                $help = "🤖 <b>Entertainment Tadka Bot</b>\n\n📢 Join our channel: @EntertainmentTadka786\n\n📋 <b>Available Commands:</b>\n/start, /checkdate, /totalupload, /testcsv, /checkcsv, /help\n\n🔍 <b>Simply type any movie name to search!</b>";
                sendMessage($chat_id, $help, null, 'HTML');
            }
        } else if (!empty(trim($text))) {
            $lang = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $lang);
            advanced_search($chat_id, $text, $user_id);
        }
    }

    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];

        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $entries = $movie_messages[$movie_lower];
            $cnt = 0;
            foreach ($entries as $entry) {
                deliver_item_to_chat($chat_id, $entry);
                usleep(200000);
                $cnt++;
            }
            sendMessage($chat_id, "✅ '$data' ke $cnt messages forward/send ho gaye!\n\n📢 Join our channel: @EntertainmentTadka786");
            answerCallbackQuery($query['id'], "🎬 $cnt items sent!");
        }
        elseif (strpos($data, 'tu_prev_') === 0) {
            $page = (int)str_replace('tu_prev_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_next_') === 0) {
            $page = (int)str_replace('tu_next_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_view_') === 0) {
            $page = (int)str_replace('tu_view_','', $data);
            $all = get_all_movies_list();
            $pg = paginate_movies($all, $page);
            forward_page_movies($chat_id, $pg['slice']);
            answerCallbackQuery($query['id'], "Re-sent current page movies");
        }
        elseif ($data === 'tu_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totalupload to start again.");
            answerCallbackQuery($query['id'], "Stopped");
        }
        elseif (strpos($data, 'uploads_page_') === 0) {
            $page = intval(str_replace('uploads_page_', '', $data));
            total_uploads($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page loaded");
        }
        elseif ($data == 'view_current_movie') {
            $message_text = $query['message']['text'] ?? '';
            if (preg_match('/Page (\d+)\/(\d+)/', $message_text, $m)) {
                $current_page = (int)$m[1];
                $all = get_all_movies_list();
                $items_per_page = ITEMS_PER_PAGE;
                $start = ($current_page - 1) * $items_per_page;
                $current_movies = array_slice($all, $start, $items_per_page);
                $forwarded = 0;
                foreach ($current_movies as $movie) {
                    if (deliver_item_to_chat($chat_id, $movie)) $forwarded++;
                    usleep(500000);
                }
                if ($forwarded > 0) sendMessage($chat_id, "✅ Current page ki $forwarded movies forward ho gayi!\n\n📢 Join: @EntertainmentTadka786");
                else sendMessage($chat_id, "❌ Kuch technical issue hai. Baad mein try karein.");
            }
            answerCallbackQuery($query['id'], "Movies forwarding...");
        }
        elseif ($data == 'uploads_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totaluploads again to restart.");
            answerCallbackQuery($query['id'], "Pagination stopped");
        }
        else {
            sendMessage($chat_id, "❌ Movie not found: " . $data);
            answerCallbackQuery($query['id'], "❌ Movie not available");
        }
    }

    // Auto group messages
    send_group_opening_message();
    send_group_closing_message();
    send_sunday_status_message();

    if (date('H:i') == '00:00') auto_backup();
    if (date('H:i') == '08:00') send_daily_digest();
}

if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . htmlspecialchars($result) . "</p>";
    echo "<p>Webhook URL: " . htmlspecialchars($webhook_url) . "</p>";
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info && isset($bot_info['ok']) && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . htmlspecialchars($bot_info['result']['first_name']) . "</p>";
        echo "<p>Username: @" . htmlspecialchars($bot_info['result']['username']) . "</p>";
        echo "<p>Channel: @EntertainmentTadka786</p>";
    }
    exit;
}

if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    echo "<h1>🎬 Entertainment Tadka Bot</h1>";
    echo "<p><strong>Telegram Channel:</strong> @EntertainmentTadka786</p>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<h3>🚀 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    echo "<h3>📋 Available Commands</h3>";
    echo "<ul>";
    echo "<li><code>/start</code> - Welcome message</li>";
    echo "<li><code>/checkdate</code> - Date-wise stats</li>";
    echo "<li><code>/totalupload</code> - Upload statistics</li>";
    echo "<li><code>/testcsv</code> - View all movies</li>";
    echo "<li><code>/checkcsv</code> - Check CSV data</li>";
    echo "<li><code>/help</code> - Help message</li>";
    echo "<li><code>/stats</code> - Admin statistics</li>";
    echo "</ul>";
    echo "<h3>📊 File Status</h3>";
    echo "<ul>";
    echo "<li>CSV File: " . (is_writable(CSV_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Users File: " . (is_writable(USERS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Stats File: " . (is_writable(STATS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "</ul>";
    echo "<h3>🌟 Special Features</h3>";
    echo "<ul>";
    echo "<li>🤖 AI-Powered Search</li>";
    echo "<li>🔔 Smart Notifications</li>";
    echo "<li>📊 Advanced Analytics</li>";
    echo "<li>🌐 Multi-Language Support</li>";
    echo "<li>⚡ Smart Caching</li>";
    echo "<li>🛡️ Auto-Backup System</li>";
    echo "<li>🎮 User Points System</li>";
    echo "<li>📅 Daily Digest</li>";
    echo "</ul>";
}
?>
