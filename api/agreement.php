<?php
/**
 * api/agreement.php
 *
 * GET                       -> { sent: [...], received: [...] }   (received = agreements where I am the seller)
 * GET ?id=12                -> one agreement with its full payload (customer or seller only)
 * POST <wizard payload>     -> create (body built by agreement.html collectAgreementPayload())
 * POST { action: "respond", agreement_id, decision: "accept"|"decline", note? }  -> seller response
 * POST { action: "cancel", agreement_id }                                          -> customer cancels a pending proposal
 */

require_once __DIR__ . '/config.php';

require_method('GET', 'POST');

$userId = require_auth();

function agreement_summary(array $r, int $userId): array
{
    return [
        'id'                     => (int) $r['id'],
        'agreement_id'           => $r['agreement_code'],
        'role'                   => (int) $r['customer_id'] === $userId ? 'customer' : 'seller',
        'customer_id'            => (int) $r['customer_id'],
        'customer_name'          => $r['customer_name'] ?? null,
        'customer_phone'         => $r['customer_phone'] ?? null,
        'seller_id'              => isset($r['seller_id']) ? (int) $r['seller_id'] : null,
        'seller_type'            => $r['seller_type'],
        'seller_name'            => $r['seller_name'],
        'seller_email'           => $r['seller_email'],
        'seller_phone'           => $r['seller_phone'],
        'product_name'           => $r['product_name'],
        'total_price'            => (float) $r['total_price'],
        'initial_deposit'        => (float) $r['initial_deposit'],
        'number_of_installments' => (int) $r['number_of_installments'],
        'payment_frequency'      => $r['payment_frequency'],
        'status'                 => $r['status'],
        'response_note'          => $r['response_note'] ?? null,
        'responded_at'           => $r['responded_at'] ?? null,
        'created_at'             => $r['created_at'],
    ];
}

/** Seller ids and email the signed-in user controls (for "received" agreements). */
function seller_identity(int $userId, array $userRow): array
{
    $sellerIds = [];
    if (table_exists('sellers')) {
        foreach (db_select('SELECT id FROM sellers WHERE user_id = ?', 'i', [$userId]) as $s) {
            $sellerIds[] = (int) $s['id'];
        }
    }
    return ['seller_ids' => $sellerIds, 'email' => strtolower((string) ($userRow['email'] ?? ''))];
}

function user_controls_agreement_as_seller(array $agreement, array $identity): bool
{
    $sid = isset($agreement['seller_id']) ? (int) $agreement['seller_id'] : 0;
    if ($sid > 0 && in_array($sid, $identity['seller_ids'], true)) {
        return true;
    }
    $email = strtolower((string) ($agreement['seller_email'] ?? ''));
    return $email !== '' && $email === $identity['email'];
}

try {
    if (!table_exists('agreements')) {
        json_error(503, 'The agreement service is not set up yet. Please run api/schema.sql on the database.');
    }

    $userRow = fetch_user($userId);
    if ($userRow === null) {
        sign_out_user();
        json_error(401, 'Your account could not be found. Please log in again.');
    }
    $identity = seller_identity($userId, $userRow);

    $baseSelect = 'SELECT a.*, u.full_name AS customer_name, u.phone AS customer_phone FROM agreements a JOIN users u ON u.id = a.customer_id';

    // ------------------------------------------------------------------ GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = isset($_GET['id']) && is_numeric((string) $_GET['id']) ? (int) $_GET['id'] : null;

        if ($id !== null) {
            $rows = db_select($baseSelect . ' WHERE a.id = ? LIMIT 1', 'i', [$id]);
            $a = $rows[0] ?? null;
            if ($a === null || ((int) $a['customer_id'] !== $userId && !user_controls_agreement_as_seller($a, $identity))) {
                json_error(404, 'Agreement not found.');
            }
            $summary = agreement_summary($a, $userId);
            $summary['payload'] = json_decode((string) ($a['payload_json'] ?? ''), true) ?: null;
            json_response(200, ['success' => true, 'data' => ['agreement' => $summary]]);
        }

        $sentRows = db_select($baseSelect . ' WHERE a.customer_id = ? ORDER BY a.created_at DESC, a.id DESC LIMIT 200', 'i', [$userId]);
        $sent = array_map(static fn(array $r) => agreement_summary($r, $userId), $sentRows);

        $received = [];
        if (!empty($identity['seller_ids']) || $identity['email'] !== '') {
            $conds  = [];
            $types  = '';
            $params = [];
            if (!empty($identity['seller_ids'])) {
                $conds[] = 'a.seller_id IN (' . implode(',', array_fill(0, count($identity['seller_ids']), '?')) . ')';
                $types  .= str_repeat('i', count($identity['seller_ids']));
                $params  = array_merge($params, $identity['seller_ids']);
            }
            if ($identity['email'] !== '') {
                $conds[]  = 'LOWER(a.seller_email) = ?';
                $types   .= 's';
                $params[] = $identity['email'];
            }
            $recRows = db_select(
                $baseSelect . ' WHERE a.customer_id <> ? AND (' . implode(' OR ', $conds) . ') ORDER BY a.created_at DESC, a.id DESC LIMIT 200',
                'i' . $types,
                array_merge([$userId], $params)
            );
            $received = array_map(static fn(array $r) => agreement_summary($r, $userId), $recRows);
        }

        json_response(200, ['success' => true, 'data' => ['sent' => $sent, 'received' => $received, 'is_seller' => !empty($identity['seller_ids'])]]);
    }

    // ------------------------------------------------------------------ POST
    $data   = request_input();
    $action = input_string($data, 'action', 40);

    // ---------- Seller accepts / declines ----------
    if ($action === 'respond') {
        $agreementId = input_int($data, 'agreement_id');
        $decision    = input_string($data, 'decision', 10);
        $note        = input_string($data, 'note', 500);
        if ($agreementId === null || !in_array($decision, ['accept', 'decline'], true)) {
            json_error(400, 'agreement_id and a decision of "accept" or "decline" are required.');
        }

        $rows = db_select($baseSelect . ' WHERE a.id = ? LIMIT 1', 'i', [$agreementId]);
        $a = $rows[0] ?? null;
        if ($a === null || !user_controls_agreement_as_seller($a, $identity)) {
            json_error(404, 'Agreement not found.');
        }
        if ($a['status'] !== 'pending_seller_approval') {
            json_error(409, 'This agreement has already been ' . str_replace('_', ' ', (string) $a['status']) . '.');
        }

        $newStatus  = $decision === 'accept' ? 'accepted' : 'declined';
        $planStatus = $decision === 'accept' ? 'active' : 'cancelled';

        $conn = db();
        $conn->begin_transaction();
        try {
            db_execute(
                'UPDATE agreements SET status = ?, response_note = ?, responded_at = NOW() WHERE id = ?',
                'ssi',
                [$newStatus, $note !== '' ? $note : null, $agreementId]
            );
            if (table_exists('installment_plans')) {
                db_execute('UPDATE installment_plans SET status = ? WHERE agreement_id = ?', 'si', [$planStatus, $agreementId]);
            }
            $conn->commit();
        } catch (Throwable $inner) {
            $conn->rollback();
            throw $inner;
        }

        add_notification((int) $a['customer_id'], 'agreement',
            $decision === 'accept' ? 'Agreement accepted' : 'Agreement declined',
            sprintf('%s has %s your agreement %s for "%s".%s',
                $a['seller_name'] ?: 'The seller', $newStatus, $a['agreement_code'], $a['product_name'],
                $note !== '' ? ' Note: ' . $note : ''));

        // Once accepted, email the finalised agreement to the seller and the customer
        if ($decision === 'accept' && site_flag('notify_email_agreement_accepted', true)) {
            $summary = sprintf(
                "Agreement: %s\nProduct/service: %s\nCustomer: %s (%s)\nSeller: %s (%s, %s)\nTotal agreed price: TZS %s\nInitial deposit: TZS %s\nInstallments: %d (%s)\nAccepted on: %s\n\nView it any time: %s",
                $a['agreement_code'], $a['product_name'], $a['customer_name'], $a['customer_phone'],
                $a['seller_name'] ?: 'Seller', $a['seller_email'] ?: '-', $a['seller_phone'] ?: '-',
                number_format((float) $a['total_price']), number_format((float) $a['initial_deposit']),
                (int) $a['number_of_installments'], $a['payment_frequency'], date('j M Y H:i'),
                app_base_url() . '/agreements.html'
            );
            $subject = cfg('app_name') . ': agreement ' . $a['agreement_code'] . ' accepted';
            if (!empty($a['seller_email'])) {
                send_mail((string) $a['seller_email'], $subject,
                    "Hello " . ($a['seller_name'] ?: 'there') . ",\n\nYou accepted the following installment agreement. Keep this email for your records.\n\n" . $summary . "\n\n— " . cfg('app_name'),
                    'agreement_accepted_seller', 'agreement', (string) $agreementId);
            }
            $customer = fetch_user((int) $a['customer_id']);
            if ($customer !== null && !empty($customer['email'])) {
                send_mail((string) $customer['email'], $subject,
                    "Hello " . $customer['full_name'] . ",\n\nYour installment agreement has been accepted by the seller.\n\n" . $summary . "\n\n— " . cfg('app_name'),
                    'agreement_accepted_customer', 'agreement', (string) $agreementId);
            }
        }

        json_response(200, ['success' => true, 'message' => 'Agreement ' . $newStatus . '.', 'agreement_status' => $newStatus]);
    }

    // ---------- Customer cancels a pending proposal ----------
    if ($action === 'cancel') {
        $agreementId = input_int($data, 'agreement_id');
        if ($agreementId === null) {
            json_error(400, 'agreement_id is required.');
        }
        $rows = db_select('SELECT * FROM agreements WHERE id = ? AND customer_id = ? LIMIT 1', 'ii', [$agreementId, $userId]);
        $a = $rows[0] ?? null;
        if ($a === null) {
            json_error(404, 'Agreement not found.');
        }
        if ($a['status'] !== 'pending_seller_approval') {
            json_error(409, 'Only agreements still awaiting seller approval can be cancelled.');
        }
        db_execute("UPDATE agreements SET status = 'cancelled', responded_at = NOW() WHERE id = ?", 'i', [$agreementId]);
        if (table_exists('installment_plans')) {
            db_execute("UPDATE installment_plans SET status = 'cancelled' WHERE agreement_id = ?", 'i', [$agreementId]);
        }
        json_response(200, ['success' => true, 'message' => 'Agreement cancelled.', 'agreement_status' => 'cancelled']);
    }

    if ($action !== '' && $action !== 'create') {
        json_error(400, 'Unknown action.');
    }

    // ---------- Create (wizard payload) ----------
    if (empty($data['seller']) && empty($data['product'])) {
        json_error(400, 'No agreement data was received.');
    }

    $seller  = is_array($data['seller'] ?? null) ? $data['seller'] : [];
    $product = is_array($data['product'] ?? null) ? $data['product'] : [];
    $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];

    $sellerType  = ($seller['type'] ?? '') === 'registered' ? 'registered' : 'unregistered';
    $sellerId    = isset($seller['seller_id']) && $seller['seller_id'] !== null && $seller['seller_id'] !== '' ? (int) $seller['seller_id'] : null;
    $sellerName  = mb_substr(trim((string) ($seller['name'] ?? '')), 0, 160);
    $sellerEmail = strtolower(trim((string) ($seller['email'] ?? '')));
    $sellerPhone = normalize_phone((string) ($seller['phone'] ?? ''));

    $productName = mb_substr(trim((string) ($product['name'] ?? '')), 0, 200);
    $productImage = trim((string) ($product['image_url'] ?? ''));
    // Accept only http(s) URLs or site-relative asset paths for the plan thumbnail
    if ($productImage !== '' && !preg_match('#^(https?://[^\s"<>]+|assets/[A-Za-z0-9_./-]+)$#', $productImage)) {
        $productImage = '';
    }
    $productImage = mb_substr($productImage, 0, 255);
    $totalPrice  = (float) ($payment['total_agreed_price'] ?? $product['total_agreed_price'] ?? 0);
    $deposit     = (float) ($payment['initial_deposit'] ?? 0);
    $count       = (int) ($payment['number_of_installments'] ?? 0);
    $frequency   = mb_substr(trim((string) ($payment['payment_frequency'] ?? 'monthly')), 0, 30);
    $schedule    = is_array($payment['schedule'] ?? null) ? $payment['schedule'] : [];

    if ($sellerType === 'registered' && $sellerId === null && $sellerEmail === '') {
        json_error(400, 'Please select a registered seller or provide the seller\'s email.');
    }
    if ($sellerType === 'unregistered' && ($sellerName === '' || !is_valid_email($sellerEmail))) {
        json_error(400, 'Please provide the seller\'s name and a valid email address.');
    }
    if ($sellerEmail !== '' && !is_valid_email($sellerEmail)) {
        json_error(400, 'The seller email address is not valid.');
    }
    if ($productName === '') {
        json_error(400, 'Please enter the product or service name.');
    }
    if ($totalPrice <= 0) {
        json_error(400, 'The total agreed price must be greater than zero.');
    }
    if ($deposit < 0 || $deposit > $totalPrice) {
        json_error(400, 'The initial deposit must be between 0 and the total price.');
    }
    if ($count < 1 || $count > 120) {
        json_error(400, 'The number of installments must be between 1 and 120.');
    }

    $sellerUserId = null;
    if ($sellerId !== null && table_exists('sellers')) {
        $rows = db_select('SELECT * FROM sellers WHERE id = ? LIMIT 1', 'i', [$sellerId]);
        if (empty($rows)) {
            json_error(404, 'The selected seller could not be found.');
        }
        $sellerName   = $sellerName !== '' ? $sellerName : (string) $rows[0]['business_name'];
        $sellerEmail  = $sellerEmail !== '' ? $sellerEmail : strtolower((string) $rows[0]['email']);
        $sellerPhone  = $sellerPhone !== '' ? $sellerPhone : (string) $rows[0]['phone'];
        $sellerUserId = isset($rows[0]['user_id']) && $rows[0]['user_id'] !== null ? (int) $rows[0]['user_id'] : null;
    } elseif ($sellerId !== null) {
        $sellerId = null;
    }

    if ($sellerUserId === $userId) {
        json_error(400, 'You cannot create an agreement with your own store.');
    }

    $code        = generate_code('PSA');
    $status      = 'pending_seller_approval';
    $payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $conn = db();
    $conn->begin_transaction();

    try {
        db_execute(
            'INSERT INTO agreements
                (agreement_code, customer_id, seller_id, seller_type, seller_name, seller_email, seller_phone,
                 product_name, total_price, initial_deposit, number_of_installments, payment_frequency, status, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'siisssssddisss',
            [$code, $userId, $sellerId, $sellerType, $sellerName, $sellerEmail, $sellerPhone,
             $productName, $totalPrice, $deposit, $count, $frequency, $status, $payloadJson]
        );
        $agreementId = (int) $conn->insert_id;

        if (table_exists('installment_plans')) {
            $firstDue    = null;
            $firstAmount = 0.0;
            if (!empty($schedule)) {
                $firstDue    = (string) ($schedule[0]['due_date'] ?? '') ?: null;
                $firstAmount = (float) ($schedule[0]['amount'] ?? 0);
            }
            if ($deposit > 0) {
                $firstAmount = $deposit; // the deposit is collected first
            }

            db_execute(
                'INSERT INTO installment_plans
                    (customer_id, agreement_id, product_name, seller_name, product_image, total_price, amount_paid,
                     next_payment_amount, next_payment_due_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'iisssdddss',
                [$userId, $agreementId, $productName, $sellerName !== '' ? $sellerName : 'Seller',
                 $productImage !== '' ? $productImage : null,
                 $totalPrice, 0.0, $firstAmount, $firstDue, 'pending']
            );
            $planId = (int) $conn->insert_id;

            if (table_exists('installment_payments')) {
                // The initial deposit is the first thing to pay: installment #0 due on the first payment date
                if ($deposit > 0) {
                    $depositDue = $firstDue ?: date('Y-m-d');
                    db_execute(
                        'INSERT INTO installment_payments (plan_id, customer_id, installment_number, amount_due, due_date, status) VALUES (?, ?, 0, ?, ?, ?)',
                        'iidss',
                        [$planId, $userId, $deposit, $depositDue, 'upcoming']
                    );
                }
                foreach ($schedule as $row) {
                    $number = (int) ($row['installment_number'] ?? 0);
                    $amount = (float) ($row['amount'] ?? 0);
                    $due    = (string) ($row['due_date'] ?? '');
                    if ($number < 1 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                        continue;
                    }
                    db_execute(
                        'INSERT INTO installment_payments (plan_id, customer_id, installment_number, amount_due, due_date, status)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        'iiidss',
                        [$planId, $userId, $number, $amount, $due, 'upcoming']
                    );
                }
            }
        }

        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }

    $displaySeller = $sellerName !== '' ? $sellerName : 'the seller';
    add_notification($userId, 'agreement', 'Agreement submitted',
        sprintf('Agreement %s for "%s" has been sent to %s for approval.', $code, $productName, $displaySeller));

    if ($sellerUserId !== null) {
        add_notification($sellerUserId, 'agreement', 'New agreement request',
            sprintf('%s proposed an installment agreement %s for "%s" (TZS %s). Review it under My Agreements.',
                $userRow['full_name'], $code, $productName, number_format($totalPrice)));
    }

    if ($sellerEmail !== '' && site_flag('notify_email_agreement_request', true)) {
        $reviewUrl = app_base_url() . '/agreements.html';
        send_mail(
            $sellerEmail,
            cfg('app_name') . ': installment agreement request ' . $code,
            sprintf("Hello %s,\n\n%s has proposed a pay-small installment agreement for \"%s\" (total TZS %s, %d installments, %s).\n\nReview and respond here: %s\n\nIf you do not have a LipaLink account yet, register with this email address to see the proposal.\n\n— %s",
                $displaySeller, $userRow['full_name'], $productName, number_format($totalPrice), $count, $frequency, $reviewUrl, cfg('app_name')),
            'agreement_request', 'agreement', (string) $agreementId
        );
    }

    json_response(201, [
        'success'          => true,
        'message'          => 'Agreement submitted successfully! The seller has been notified for approval.',
        'agreement_id'     => $code,
        'agreement_status' => $status,
    ]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'agreement');
}
