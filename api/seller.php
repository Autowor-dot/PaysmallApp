<?php
/**
 * api/seller.php — seller hub for the signed-in user's store.
 *
 * GET                                   -> { seller, stats, agreements, payments, products, schedules }
 * POST { action: "update_profile", ... }          business profile + installment defaults
 * POST { action: "save_product", ... }            create / update a listing
 * POST { action: "set_product_status", id, status }
 * POST { action: "record_payment", agreement_id, amount, method, reference?, note? }
 *      Records a payment the seller received directly (cash, mobile money to their own number).
 *      It is stored as a MANUAL transaction (verified_via = 'manual'), clearly distinct from
 *      provider-verified gateway payments, and settles the next unpaid installment.
 *
 * A user is a seller when a sellers row has user_id = their id. Rows registered with the
 * same email before the user signed up are linked automatically.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payments-lib.php';

require_method('GET', 'POST');

$userId = require_auth();

const SELLER_PAYMENT_METHODS = ['Cash', 'M-Pesa', 'Tigo Pesa', 'Airtel Money', 'HaloPesa', 'Bank Transfer'];
const SELLER_FREQUENCIES     = ['daily', 'weekly', 'biweekly', 'monthly'];
const SELLER_DELIVERY        = ['after_full_payment', 'after_initial_deposit', 'after_specified_threshold'];

function seller_for_user(int $userId, array $userRow): ?array
{
    if (!table_exists('sellers')) {
        return null;
    }
    $rows = db_select('SELECT * FROM sellers WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
    if (!empty($rows)) {
        return $rows[0];
    }
    // Link a store registered with this email before the account existed
    $email = strtolower((string) ($userRow['email'] ?? ''));
    if ($email !== '') {
        db_execute('UPDATE sellers SET user_id = ? WHERE user_id IS NULL AND LOWER(email) = ?', 'is', [$userId, $email]);
        $rows = db_select('SELECT * FROM sellers WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
        if (!empty($rows)) {
            return $rows[0];
        }
    }
    return null;
}

function public_seller(array $s): array
{
    return [
        'id'                      => (int) $s['id'],
        'business_name'           => $s['business_name'],
        'full_name'               => $s['full_name'],
        'phone'                   => $s['phone'],
        'email'                   => $s['email'],
        'location'                => $s['location'],
        'category'                => $s['category'],
        'description'             => $s['description'] ?? '',
        'payout_method'           => $s['payout_method'],
        'status'                  => $s['status'],
        'status_reason'           => $s['status_reason'] ?? null,
        'default_deposit_percent' => (float) ($s['default_deposit_percent'] ?? 20),
        'default_frequency'       => $s['default_frequency'] ?? 'weekly',
        'default_installments'    => (int) ($s['default_installments'] ?? 12),
        'delivery_policy'         => $s['delivery_policy'] ?? 'after_full_payment',
        'created_at'              => $s['created_at'],
    ];
}

/** WHERE fragment matching agreements that belong to this seller (by id or email). */
function seller_agreement_condition(array $seller): array
{
    return ['(a.seller_id = ? OR LOWER(a.seller_email) = ?)', 'is', [(int) $seller['id'], strtolower((string) $seller['email'])]];
}

try {
    $userRow = fetch_user($userId);
    if ($userRow === null) {
        sign_out_user();
        json_error(401, 'Your account could not be found. Please log in again.');
    }
    $seller = seller_for_user($userId, $userRow);

    // ------------------------------------------------------------------ GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($seller === null) {
            json_response(200, ['success' => true, 'data' => ['seller' => null]]);
        }
        [$cond, $types, $params] = seller_agreement_condition($seller);

        $agreements = [];
        $planIds = [];
        if (table_exists('agreements')) {
            $rows = db_select(
                "SELECT a.*, u.full_name AS customer_name, u.phone AS customer_phone, u.email AS customer_email,
                        pl.id AS plan_id, pl.amount_paid, pl.status AS plan_status, pl.next_payment_amount, pl.next_payment_due_date,
                        (SELECT COUNT(*) FROM installment_payments p WHERE p.plan_id = pl.id AND p.status <> 'paid' AND p.due_date < CURDATE()) AS overdue_count,
                        (SELECT COALESCE(SUM(p.amount_due),0) FROM installment_payments p WHERE p.plan_id = pl.id AND p.status <> 'paid' AND p.due_date < CURDATE()) AS overdue_amount
                 FROM agreements a
                 JOIN users u ON u.id = a.customer_id
                 LEFT JOIN installment_plans pl ON pl.agreement_id = a.id
                 WHERE {$cond}
                 ORDER BY a.created_at DESC, a.id DESC LIMIT 300",
                $types, $params
            );
            foreach ($rows as $r) {
                $total = (float) $r['total_price'];
                $paid  = (float) ($r['amount_paid'] ?? 0);
                $state = $r['status'];
                if ($state === 'accepted') {
                    $state = ($r['plan_status'] ?? '') === 'completed' ? 'completed' : (($r['plan_status'] ?? '') === 'cancelled' ? 'cancelled' : 'active');
                }
                $agreements[] = [
                    'id'                     => (int) $r['id'],
                    'agreement_id'           => $r['agreement_code'],
                    'status'                 => $r['status'],
                    'state'                  => $state,
                    'customer_id'            => (int) $r['customer_id'],
                    'customer_name'          => $r['customer_name'],
                    'customer_phone'         => $r['customer_phone'],
                    'customer_email'         => $r['customer_email'],
                    'product_name'           => $r['product_name'],
                    'total_price'            => $total,
                    'initial_deposit'        => (float) $r['initial_deposit'],
                    'number_of_installments' => (int) $r['number_of_installments'],
                    'payment_frequency'      => $r['payment_frequency'],
                    'plan_id'                => isset($r['plan_id']) ? (int) $r['plan_id'] : null,
                    'amount_paid'            => $paid,
                    'remaining_balance'      => max(0.0, $total - $paid),
                    'completion_percentage'  => $total > 0 ? (int) round($paid / $total * 100) : 0,
                    'next_payment_amount'    => (float) ($r['next_payment_amount'] ?? 0),
                    'next_payment_due_date'  => $r['next_payment_due_date'] ?? null,
                    'overdue_count'          => (int) ($r['overdue_count'] ?? 0),
                    'overdue_amount'         => (float) ($r['overdue_amount'] ?? 0),
                    'response_note'          => $r['response_note'] ?? null,
                    'responded_at'           => $r['responded_at'] ?? null,
                    'created_at'             => $r['created_at'],
                ];
                if (!empty($r['plan_id'])) {
                    $planIds[] = (int) $r['plan_id'];
                }
            }
        }

        // Installment schedules for the seller's plans
        $schedules = [];
        if ($planIds && table_exists('installment_payments')) {
            $in = implode(',', array_fill(0, count($planIds), '?'));
            $rows = db_select(
                "SELECT id, plan_id, installment_number, amount_due, due_date, status, paid_at FROM installment_payments WHERE plan_id IN ({$in}) ORDER BY plan_id, installment_number",
                str_repeat('i', count($planIds)), $planIds
            );
            foreach ($rows as $p) {
                $schedules[(string) $p['plan_id']][] = [
                    'id' => (int) $p['id'], 'installment_number' => (int) $p['installment_number'], 'amount_due' => (float) $p['amount_due'],
                    'due_date' => $p['due_date'], 'status' => $p['status'], 'paid_at' => $p['paid_at'],
                ];
            }
        }

        // Payments received on the seller's agreements
        $payments = [];
        if ($planIds && table_exists('transactions')) {
            $in = implode(',', array_fill(0, count($planIds), '?'));
            $rows = db_select(
                "SELECT t.*, u.full_name AS customer_name FROM transactions t JOIN users u ON u.id = t.customer_id
                 WHERE t.plan_id IN ({$in}) ORDER BY t.created_at DESC, t.id DESC LIMIT 300",
                str_repeat('i', count($planIds)), $planIds
            );
            foreach ($rows as $t) {
                $verified = $t['verified_via'] ?? null;
                if ($verified === null && ($t['status'] ?? '') === 'completed') {
                    $verified = str_starts_with((string) ($t['provider_reference'] ?? ''), 'SIMULATED-') ? 'simulator' : 'unverified';
                }
                $payments[] = [
                    'id'                 => (int) $t['id'],
                    'transaction_id'     => $t['transaction_code'],
                    'plan_id'            => (int) $t['plan_id'],
                    'customer_name'      => $t['customer_name'],
                    'product_name'       => $t['product_name'],
                    'amount'             => (float) $t['amount'],
                    'payment_method'     => $t['payment_method'],
                    'provider_reference' => $t['provider_reference'] ?? null,
                    'status'             => ucfirst((string) $t['status']),
                    'verified_via'       => $verified,
                    'recorded_by_type'   => $t['recorded_by_type'] ?? 'customer',
                    'note'               => $t['note'] ?? null,
                    'created_at'         => $t['created_at'],
                    'confirmed_at'       => $t['confirmed_at'] ?? null,
                ];
            }
        }

        $products = [];
        if (table_exists('products')) {
            foreach (db_select('SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC', 'i', [(int) $seller['id']]) as $p) {
                $products[] = [
                    'id' => (int) $p['id'], 'name' => $p['name'], 'description' => $p['description'], 'category' => $p['category'],
                    'price' => (float) $p['price'], 'installment_amount' => (float) $p['installment_amount'],
                    'installment_frequency' => $p['installment_frequency'], 'installments_count' => (int) $p['installments_count'],
                    'image_url' => $p['image_url'], 'stock' => $p['stock'] !== null ? (int) $p['stock'] : null,
                    'featured' => (bool) $p['featured'], 'status' => $p['status'], 'created_at' => $p['created_at'],
                ];
            }
        }

        // Stats
        $stats = ['pending' => 0, 'active' => 0, 'completed' => 0, 'declined' => 0, 'cancelled' => 0, 'overdue' => 0,
            'customers' => 0, 'expected' => 0.0, 'recorded' => 0.0, 'outstanding' => 0.0, 'overdue_amount' => 0.0, 'products' => count($products)];
        $customers = [];
        foreach ($agreements as $a) {
            $customers[$a['customer_id']] = true;
            if ($a['state'] === 'pending_seller_approval') $stats['pending']++;
            elseif (isset($stats[$a['state']])) $stats[$a['state']]++;
            if ($a['state'] === 'active' || $a['state'] === 'completed') {
                $stats['expected'] += $a['total_price'];
                $stats['outstanding'] += $a['remaining_balance'];
            }
            if ($a['overdue_count'] > 0 && $a['state'] === 'active') {
                $stats['overdue']++;
                $stats['overdue_amount'] += $a['overdue_amount'];
            }
        }
        $stats['customers'] = count($customers);
        foreach ($payments as $p) {
            if ($p['status'] === 'Completed') {
                $stats['recorded'] += $p['amount'];
            }
        }

        json_response(200, ['success' => true, 'data' => [
            'seller' => public_seller($seller), 'stats' => $stats, 'agreements' => $agreements,
            'schedules' => $schedules, 'payments' => $payments, 'products' => $products,
            'payment_methods' => SELLER_PAYMENT_METHODS,
        ]]);
    }

    // ------------------------------------------------------------------ POST
    $input  = request_input();
    $action = input_string($input, 'action', 40);

    if ($seller === null) {
        json_error(403, 'You have not registered a store yet. Register your store first.');
    }
    if (($seller['status'] ?? 'pending') === 'suspended') {
        json_error(403, 'Your store is suspended. Please contact support.');
    }

    switch ($action) {
        case 'update_profile':
            $businessName = input_string($input, 'business_name', 160);
            $fullName     = input_string($input, 'full_name', 120);
            $phone        = normalize_phone(input_string($input, 'phone', 30));
            $email        = strtolower(input_string($input, 'email', 190));
            $location     = input_string($input, 'location', 160);
            $category     = input_string($input, 'category', 80);
            $description  = mb_substr(trim((string) ($input['description'] ?? '')), 0, 1000);
            $payout       = input_string($input, 'payout_method', 30);
            $depositPct   = (float) ($input['default_deposit_percent'] ?? 20);
            $frequency    = input_string($input, 'default_frequency', 20);
            $installments = (int) ($input['default_installments'] ?? 12);
            $delivery     = input_string($input, 'delivery_policy', 40);

            if (mb_strlen($businessName) < 2) json_error(400, 'Please enter your business name.');
            if (mb_strlen($fullName) < 2) json_error(400, 'Please enter the owner\'s full name.');
            if (!is_valid_tz_phone($phone)) json_error(400, 'Please enter a valid Tanzanian phone number.');
            if (!is_valid_email($email)) json_error(400, 'Please enter a valid business email address.');
            if (!in_array($payout, ['mobile_money', 'bank'], true)) $payout = 'mobile_money';
            if ($depositPct < 0 || $depositPct > 100) json_error(400, 'The deposit percentage must be between 0 and 100.');
            if (!in_array($frequency, SELLER_FREQUENCIES, true)) $frequency = 'weekly';
            if ($installments < 1 || $installments > 120) json_error(400, 'Default installments must be between 1 and 120.');
            if (!in_array($delivery, SELLER_DELIVERY, true)) $delivery = 'after_full_payment';

            $clash = db_select('SELECT id FROM sellers WHERE (email = ? OR phone = ?) AND id <> ? LIMIT 1', 'ssi', [$email, $phone, (int) $seller['id']]);
            if (!empty($clash)) json_error(409, 'Another store already uses that email or phone number.');

            $hasDefaults = column_exists('sellers', 'default_deposit_percent');
            if ($hasDefaults) {
                db_execute(
                    'UPDATE sellers SET business_name = ?, full_name = ?, phone = ?, email = ?, location = ?, category = ?, description = ?, payout_method = ?,
                        default_deposit_percent = ?, default_frequency = ?, default_installments = ?, delivery_policy = ? WHERE id = ?',
                    'ssssssssdsisi',
                    [$businessName, $fullName, $phone, $email, $location, $category, $description, $payout, $depositPct, $frequency, $installments, $delivery, (int) $seller['id']]
                );
            } else {
                db_execute('UPDATE sellers SET business_name = ?, full_name = ?, phone = ?, email = ?, location = ?, category = ?, payout_method = ? WHERE id = ?',
                    'sssssssi', [$businessName, $fullName, $phone, $email, $location, $category, $payout, (int) $seller['id']]);
            }
            $seller = db_select('SELECT * FROM sellers WHERE id = ? LIMIT 1', 'i', [(int) $seller['id']])[0];
            json_response(200, ['success' => true, 'message' => 'Store profile updated.', 'data' => ['seller' => public_seller($seller)]]);
            break;

        case 'save_product':
            if (!table_exists('products')) json_error(503, 'Products are not set up yet. Run api/install.php.');
            $id          = input_int($input, 'id');
            $name        = input_string($input, 'name', 200);
            $description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 2000);
            $category    = input_string($input, 'category', 80) ?: 'General';
            $price       = (float) ($input['price'] ?? 0);
            $installment = (float) ($input['installment_amount'] ?? 0);
            $frequency   = input_string($input, 'installment_frequency', 20);
            $count       = (int) ($input['installments_count'] ?? 0);
            $image       = input_string($input, 'image_url', 255);
            $stock       = input_int($input, 'stock');
            $status      = input_string($input, 'status', 20) === 'inactive' ? 'inactive' : 'active';

            if (mb_strlen($name) < 2) json_error(400, 'Please enter the product name.');
            if ($price <= 0) json_error(400, 'Please enter a price greater than zero.');
            if (!in_array($frequency, SELLER_FREQUENCIES, true)) $frequency = (string) ($seller['default_frequency'] ?? 'weekly');
            if ($count < 1 || $count > 120) $count = (int) ($seller['default_installments'] ?? 12);
            if ($installment <= 0) $installment = round($price * (1 - ((float) ($seller['default_deposit_percent'] ?? 20)) / 100) / $count);
            if ($image !== '' && !preg_match('#^(https?://[^\s"<>]+|assets/[A-Za-z0-9_./-]+)$#', $image)) json_error(400, 'The image must be an http(s) URL.');

            if ($id !== null) {
                $own = db_select('SELECT id FROM products WHERE id = ? AND seller_id = ? LIMIT 1', 'ii', [$id, (int) $seller['id']]);
                if (empty($own)) json_error(404, 'Product not found.');
                db_execute(
                    'UPDATE products SET name = ?, description = ?, category = ?, price = ?, installment_amount = ?, installment_frequency = ?, installments_count = ?, image_url = ?, stock = ?, status = ? WHERE id = ?',
                    'sssddsisisi',
                    [$name, $description, $category, $price, $installment, $frequency, $count, $image !== '' ? $image : null, $stock, $status, $id]
                );
                json_response(200, ['success' => true, 'message' => 'Product updated.', 'product_id' => $id]);
            }

            $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?: 'product';
            $slug = trim($base, '-') . '-' . $seller['id'] . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            db_execute(
                'INSERT INTO products (seller_id, slug, name, description, category, price, installment_amount, installment_frequency, installments_count, image_url, stock, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'issssddsisis',
                [(int) $seller['id'], $slug, $name, $description, $category, $price, $installment, $frequency, $count, $image !== '' ? $image : null, $stock, $status]
            );
            json_response(201, ['success' => true, 'message' => 'Product listed.', 'product_id' => (int) db()->insert_id]);
            break;

        case 'set_product_status':
            $id = input_int($input, 'id');
            $status = input_string($input, 'status', 20) === 'inactive' ? 'inactive' : 'active';
            if ($id === null) json_error(400, 'Product id is required.');
            $n = db_execute('UPDATE products SET status = ? WHERE id = ? AND seller_id = ?', 'sii', [$status, $id, (int) $seller['id']]);
            if ($n === 0) json_error(404, 'Product not found.');
            json_response(200, ['success' => true, 'message' => $status === 'active' ? 'Product is live.' : 'Product hidden from the marketplace.']);
            break;

        case 'record_payment':
            if (!table_exists('transactions') || !table_exists('installment_plans')) json_error(503, 'Payments are not set up yet.');
            $agreementId = input_int($input, 'agreement_id');
            $amount      = (float) ($input['amount'] ?? 0);
            $method      = input_string($input, 'method', 40);
            $reference   = input_string($input, 'reference', 100);
            $note        = input_string($input, 'note', 255);
            if ($agreementId === null) json_error(400, 'agreement_id is required.');
            if ($amount < 500) json_error(400, 'The minimum amount is TZS 500.');
            if (!in_array($method, SELLER_PAYMENT_METHODS, true)) json_error(400, 'Choose a valid payment method.');

            [$cond, $types, $params] = seller_agreement_condition($seller);
            $rows = db_select(
                "SELECT a.*, pl.id AS plan_id, pl.status AS plan_status, pl.total_price AS plan_total, pl.amount_paid, pl.product_name AS plan_product, pl.seller_name AS plan_seller
                 FROM agreements a LEFT JOIN installment_plans pl ON pl.agreement_id = a.id WHERE a.id = ? AND {$cond} LIMIT 1",
                'i' . $types, array_merge([$agreementId], $params)
            );
            $a = $rows[0] ?? null;
            if ($a === null) json_error(404, 'Agreement not found.');
            if ($a['status'] !== 'accepted') json_error(409, 'Payments can only be recorded on accepted agreements.');
            if (empty($a['plan_id']) || in_array($a['plan_status'], ['completed', 'cancelled'], true)) json_error(409, 'This plan is already ' . ($a['plan_status'] ?: 'closed') . '.');
            $remaining = max(0.0, (float) $a['plan_total'] - (float) $a['amount_paid']);
            if ($amount > $remaining + 0.005) json_error(400, 'The amount exceeds the remaining balance of TZS ' . number_format($remaining) . '.');

            $next = db_select("SELECT id FROM installment_payments WHERE plan_id = ? AND status <> 'paid' ORDER BY due_date ASC, installment_number ASC LIMIT 1", 'i', [(int) $a['plan_id']]);
            $paymentId = isset($next[0]) ? (int) $next[0]['id'] : null;

            $code = generate_code('TX');
            $hasRecordedBy = column_exists('transactions', 'recorded_by_type');
            if ($hasRecordedBy) {
                db_execute(
                    'INSERT INTO transactions (customer_id, plan_id, payment_id, transaction_code, product_name, seller_name, amount, payment_method, provider_reference, status, recorded_by_type, recorded_by_id, note)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'iiisssdssssis',
                    [(int) $a['customer_id'], (int) $a['plan_id'], $paymentId, $code, $a['plan_product'], $a['plan_seller'], $amount, $method,
                     $reference !== '' ? $reference : null, 'pending', 'seller', (int) $seller['id'], $note !== '' ? $note : null]
                );
            } else {
                db_execute(
                    'INSERT INTO transactions (customer_id, plan_id, payment_id, transaction_code, product_name, seller_name, amount, payment_method, provider_reference, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'iiisssdsss',
                    [(int) $a['customer_id'], (int) $a['plan_id'], $paymentId, $code, $a['plan_product'], $a['plan_seller'], $amount, $method, $reference !== '' ? $reference : null, 'pending']
                );
            }
            $tx = fetch_transaction_by_code($code);
            $updated = apply_payment_result($tx, 'completed', $reference !== '' ? $reference : null, 'manual');

            json_response(201, [
                'success' => true,
                'message' => 'Payment recorded as a manual entry and the installment plan was updated. A receipt with the updated balance was emailed to you and the customer (check spam folders if it does not appear).',
                'data'    => ['transaction' => public_transaction($updated)],
            ]);
            break;

        default:
            json_error(400, 'Unknown action.');
    }
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        json_error(409, 'That email or phone number is already in use.');
    }
    server_error($e, 'seller hub');
}
