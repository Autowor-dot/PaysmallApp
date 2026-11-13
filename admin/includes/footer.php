<?php declare(strict_types=1); ?>
        </main>
        <footer class="footer muted">
            <?= e(site_setting('site_name', (string) cfg('app_name', 'LipaLink'))) ?> admin console · signed in as <?= e($admin['email'] ?? '') ?> · server time <?= e(date('j M Y H:i')) ?> (EAT)
        </footer>
    </div>
</div>
<?php if (!empty($useCharts)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<?php endif; ?>
<script src="assets/admin.js?v=1"></script>
</body>
</html>
