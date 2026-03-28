<?php
session_start();

$dbFile = __DIR__ . '/crediario.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function initDatabase(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        usuario TEXT NOT NULL UNIQUE,
        senha TEXT NOT NULL,
        tipo TEXT NOT NULL DEFAULT 'usuario',
        ativo INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pessoas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        telefone TEXT,
        cpf TEXT,
        endereco TEXT,
        observacoes TEXT,
        status_pessoa TEXT NOT NULL DEFAULT 'ativo',
        status_pagamento TEXT NOT NULL DEFAULT 'aberto',
        data_entrega TEXT,
        data_pagamento TEXT,
        valor REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lancamentos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tipo TEXT NOT NULL,
        descricao TEXT NOT NULL,
        valor REAL NOT NULL,
        categoria TEXT,
        data_lancamento TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        nome_sistema TEXT NOT NULL DEFAULT 'Crediário Fácil',
        empresa TEXT NOT NULL DEFAULT 'Minha Loja',
        saudacao TEXT DEFAULT 'Bem-vindo ao sistema financeiro',
        logo_url TEXT DEFAULT '',
        capa_url TEXT DEFAULT '',
        meta_economia REAL NOT NULL DEFAULT 500,
        limite_gastos REAL NOT NULL DEFAULT 2000
    )");

    $exists = $pdo->query("SELECT COUNT(*) as total FROM users")->fetch();
    if ((int)$exists['total'] === 0) {
        $senhaHash = password_hash('123456', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (nome, usuario, senha, tipo, ativo) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Administrador', 'admin', $senhaHash, 'admin', 1]);
    }

    $configExists = $pdo->query("SELECT COUNT(*) as total FROM configuracoes")->fetch();
    if ((int)$configExists['total'] === 0) {
        $pdo->exec("INSERT INTO configuracoes (id) VALUES (1)");
    }
}

initDatabase($pdo);

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function currentUser(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function isAdmin(?array $user): bool {
    return $user && $user['tipo'] === 'admin';
}

function redirect(string $url = 'index.php'): void {
    header('Location: ' . $url);
    exit;
}

function formatMoney($value): string {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function formatDateBr(?string $date): string {
    if (!$date) return '-';
    $time = strtotime($date);
    if (!$time) return h($date);
    return date('d/m/Y', $time);
}

function daysUntil(?string $date): ?int {
    if (!$date) return null;
    $today = new DateTime(date('Y-m-d'));
    $target = DateTime::createFromFormat('Y-m-d', $date);
    if (!$target) return null;
    return (int)$today->diff($target)->format('%r%a');
}

function getConfig(PDO $pdo): array {
    return $pdo->query("SELECT * FROM configuracoes WHERE id = 1")->fetch() ?: [];
}

function analyzeFinance(PDO $pdo): array {
    $entradas = (float)($pdo->query("SELECT COALESCE(SUM(valor),0) total FROM lancamentos WHERE tipo = 'entrada'")->fetch()['total'] ?? 0);
    $saidas = (float)($pdo->query("SELECT COALESCE(SUM(valor),0) total FROM lancamentos WHERE tipo = 'saida'")->fetch()['total'] ?? 0);
    $saldo = $entradas - $saidas;
    $config = getConfig($pdo);
    $meta = (float)($config['meta_economia'] ?? 0);
    $limite = (float)($config['limite_gastos'] ?? 0);

    $categoria = $pdo->query("SELECT categoria, SUM(valor) total FROM lancamentos WHERE tipo = 'saida' GROUP BY categoria ORDER BY total DESC LIMIT 1")->fetch();

    $diagnostico = 'Situação equilibrada.';
    $sugestao = 'Continue acompanhando entradas, saídas e vencimentos.';

    if ($saldo < 0) {
        $diagnostico = 'Alerta financeiro: as saídas estão maiores que as entradas.';
        $sugestao = 'Reduza despesas, cobre clientes em aberto e reorganize o fluxo de caixa.';
    } elseif ($limite > 0 && $saidas > $limite) {
        $diagnostico = 'Os gastos ultrapassaram o limite definido.';
        $sugestao = 'Revise a categoria com maior custo e ajuste o orçamento.';
    } elseif ($meta > 0 && $saldo >= $meta) {
        $diagnostico = 'Parabéns, a meta de economia foi atingida.';
        $sugestao = 'Separe uma reserva financeira ou reinvista parte do saldo.';
    }

    return [
        'entradas' => $entradas,
        'saidas' => $saidas,
        'saldo' => $saldo,
        'diagnostico' => $diagnostico,
        'sugestao' => $sugestao,
        'categoria' => $categoria,
    ];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user = currentUser($pdo);

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = trim($_POST['senha'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM users WHERE usuario = ? AND ativo = 1 LIMIT 1");
    $stmt->execute([$usuario]);
    $found = $stmt->fetch();

    if ($found && password_verify($senha, $found['senha'])) {
        $_SESSION['user_id'] = $found['id'];
        redirect('index.php');
    }

    $_SESSION['flash_error'] = 'Usuário ou senha inválidos.';
    redirect('index.php');
}

if ($action === 'logout') {
    session_destroy();
    redirect('index.php');
}

if (!$user && $action !== 'login') {
    $config = getConfig($pdo);
    $error = $_SESSION['flash_error'] ?? '';
    unset($_SESSION['flash_error']);
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= h($config['nome_sistema'] ?? 'Crediário Fácil') ?></title>
        <style>
            body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:0}
            .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
            .card{width:100%;max-width:420px;background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:24px}
            .input{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:12px;margin-top:6px;box-sizing:border-box}
            .btn{width:100%;padding:12px;border:0;border-radius:12px;background:#111827;color:#fff;font-weight:700;cursor:pointer;margin-top:16px}
            label{display:block;margin-top:12px;font-weight:700;font-size:14px}
            .small{color:#6b7280;font-size:14px}
            .logo{width:76px;height:76px;border-radius:18px;object-fit:cover;display:block;margin:0 auto 12px auto}
            .alert{background:#fff7ed;border:1px solid #fdba74;color:#9a3412;padding:12px;border-radius:12px;margin-top:14px}
        </style>
    </head>
    <body>
        <div class="wrap">
            <div class="card">
                <?php if (!empty($config['logo_url'])): ?>
                    <img class="logo" src="<?= h($config['logo_url']) ?>" alt="Logo">
                <?php endif; ?>
                <h2 style="text-align:center;margin:0"><?= h($config['nome_sistema'] ?? 'Crediário Fácil') ?></h2>
                <p class="small" style="text-align:center">Login do sistema</p>

                <?php if ($error): ?>
                    <div class="alert"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <label>Usuário</label>
                    <input class="input" name="usuario" value="admin">
                    <label>Senha</label>
                    <input class="input" type="password" name="senha" value="123456">
                    <button class="btn" type="submit">Entrar</button>
                </form>

                <div class="alert">
                    Login inicial: usuário <b>admin</b> e senha <b>123456</b>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    if ($action === 'save_pessoa') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            trim($_POST['nome'] ?? ''),
            trim($_POST['telefone'] ?? ''),
            trim($_POST['cpf'] ?? ''),
            trim($_POST['endereco'] ?? ''),
            trim($_POST['observacoes'] ?? ''),
            $_POST['status_pessoa'] ?? 'ativo',
            $_POST['status_pagamento'] ?? 'aberto',
            $_POST['data_entrega'] ?? null,
            $_POST['data_pagamento'] ?? null,
            (float)($_POST['valor'] ?? 0),
        ];

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE pessoas SET nome=?, telefone=?, cpf=?, endereco=?, observacoes=?, status_pessoa=?, status_pagamento=?, data_entrega=?, data_pagamento=?, valor=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([...$data, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO pessoas (nome, telefone, cpf, endereco, observacoes, status_pessoa, status_pagamento, data_entrega, data_pagamento, valor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute($data);
        }
        redirect('index.php?tab=pessoas');
    }

    if ($action === 'save_lancamento') {
        $stmt = $pdo->prepare("INSERT INTO lancamentos (tipo, descricao, valor, categoria, data_lancamento) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['tipo'] ?? 'entrada',
            trim($_POST['descricao'] ?? ''),
            (float)($_POST['valor'] ?? 0),
            trim($_POST['categoria'] ?? 'Outros'),
            $_POST['data_lancamento'] ?? date('Y-m-d'),
        ]);
        redirect('index.php?tab=financas');
    }

    if ($action === 'save_user' && isAdmin($user)) {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $tipo = $_POST['tipo'] ?? 'usuario';
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        $senha = trim($_POST['senha'] ?? '');

        if ($id > 0) {
            if ($senha !== '') {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET nome=?, usuario=?, senha=?, tipo=?, ativo=? WHERE id=?");
                $stmt->execute([$nome, $usuario, $hash, $tipo, $ativo, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET nome=?, usuario=?, tipo=?, ativo=? WHERE id=?");
                $stmt->execute([$nome, $usuario, $tipo, $ativo, $id]);
            }
        } else {
            $hash = password_hash($senha !== '' ? $senha : '123456', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (nome, usuario, senha, tipo, ativo) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nome, $usuario, $hash, $tipo, $ativo]);
        }
        redirect('index.php?tab=usuarios');
    }

    if ($action === 'save_config' && isAdmin($user)) {
        $stmt = $pdo->prepare("UPDATE configuracoes SET nome_sistema=?, empresa=?, saudacao=?, logo_url=?, capa_url=?, meta_economia=?, limite_gastos=? WHERE id=1");
        $stmt->execute([
            trim($_POST['nome_sistema'] ?? 'Crediário Fácil'),
            trim($_POST['empresa'] ?? 'Minha Loja'),
            trim($_POST['saudacao'] ?? ''),
            trim($_POST['logo_url'] ?? ''),
            trim($_POST['capa_url'] ?? ''),
            (float)($_POST['meta_economia'] ?? 0),
            (float)($_POST['limite_gastos'] ?? 0),
        ]);
        redirect('index.php?tab=config');
    }
}

if ($user) {
    if ($action === 'delete_pessoa' && isAdmin($user)) {
        $stmt = $pdo->prepare("DELETE FROM pessoas WHERE id = ?");
        $stmt->execute([(int)($_GET['id'] ?? 0)]);
        redirect('index.php?tab=pessoas');
    }

    if ($action === 'toggle_pessoa') {
        $stmt = $pdo->prepare("UPDATE pessoas SET status_pessoa = CASE WHEN status_pessoa = 'ativo' THEN 'inativo' ELSE 'ativo' END, updated_at=CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([(int)($_GET['id'] ?? 0)]);
        redirect('index.php?tab=pessoas');
    }

    if ($action === 'delete_lancamento') {
        $stmt = $pdo->prepare("DELETE FROM lancamentos WHERE id = ?");
        $stmt->execute([(int)($_GET['id'] ?? 0)]);
        redirect('index.php?tab=financas');
    }

    if ($action === 'delete_user' && isAdmin($user)) {
        $id = (int)($_GET['id'] ?? 0);
        if ($id !== (int)$user['id']) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
        }
        redirect('index.php?tab=usuarios');
    }
}

$config = getConfig($pdo);
$tab = $_GET['tab'] ?? 'dashboard';
$busca = trim($_GET['busca'] ?? '');
$editPessoa = null;
$editUser = null;

if (isset($_GET['edit_pessoa'])) {
    $stmt = $pdo->prepare("SELECT * FROM pessoas WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_pessoa']]);
    $editPessoa = $stmt->fetch();
}

if (isset($_GET['edit_user']) && isAdmin($user)) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_user']]);
    $editUser = $stmt->fetch();
}

if ($busca !== '') {
    $stmt = $pdo->prepare("SELECT * FROM pessoas WHERE nome LIKE ? OR telefone LIKE ? OR cpf LIKE ? OR endereco LIKE ? ORDER BY id DESC");
    $like = '%' . $busca . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $pessoas = $stmt->fetchAll();
} else {
    $pessoas = $pdo->query("SELECT * FROM pessoas ORDER BY id DESC")->fetchAll();
}

$users = isAdmin($user) ? $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll() : [];
$entradas = $pdo->query("SELECT * FROM lancamentos WHERE tipo = 'entrada' ORDER BY id DESC")->fetchAll();
$saidas = $pdo->query("SELECT * FROM lancamentos WHERE tipo = 'saida' ORDER BY id DESC")->fetchAll();
$finance = analyzeFinance($pdo);
$totalPessoas = (int)($pdo->query("SELECT COUNT(*) total FROM pessoas")->fetch()['total'] ?? 0);
$totalAberto = (int)($pdo->query("SELECT COUNT(*) total FROM pessoas WHERE status_pagamento = 'aberto'")->fetch()['total'] ?? 0);
$totalPago = (int)($pdo->query("SELECT COUNT(*) total FROM pessoas WHERE status_pagamento = 'pago'")->fetch()['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($config['nome_sistema']) ?></title>
    <style>
        *{box-sizing:border-box} body{margin:0;font-family:Arial,sans-serif;background:#f3f4f6;color:#111827}
        .container{max-width:1280px;margin:0 auto;padding:20px}
        .card{background:#fff;border-radius:18px;padding:18px;box-shadow:0 10px 28px rgba(0,0,0,.08)}
        .grid{display:grid;gap:16px}.grid-2{grid-template-columns:repeat(2,1fr)}.grid-3{grid-template-columns:repeat(3,1fr)}.grid-4{grid-template-columns:repeat(4,1fr)}.main{grid-template-columns:1fr 2fr}
        @media(max-width:980px){.grid-2,.grid-3,.grid-4,.main{grid-template-columns:1fr}.top{flex-direction:column;align-items:flex-start}}
        .top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}
        .btn{display:inline-block;padding:10px 14px;border-radius:12px;text-decoration:none;border:0;cursor:pointer;font-weight:700}
        .btn-dark{background:#111827;color:#fff}.btn-gray{background:#e5e7eb;color:#111827}.btn-red{background:#dc2626;color:#fff}.btn-yellow{background:#f59e0b;color:#fff}
        .input,.select,.textarea{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:12px;margin-top:6px}
        .textarea{min-height:90px}.small{font-size:14px;color:#6b7280}.tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
        .tab{padding:10px 14px;border-radius:12px;background:#e5e7eb;color:#111827;text-decoration:none;font-weight:700}.tab.active{background:#111827;color:#fff}
        .metric{font-size:30px;font-weight:900;margin-top:8px}.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#fff}
        .blue{background:#2563eb}.gray{background:#6b7280}.green{background:#16a34a}.red{background:#dc2626}.yellow{background:#f59e0b}
        .item{border:1px solid #e5e7eb;border-radius:18px;padding:16px;margin-bottom:14px;background:#fff}.row{display:flex;gap:10px;flex-wrap:wrap}.space{display:flex;justify-content:space-between;align-items:center;gap:16px}.mini{background:#f9fafb;border-radius:14px;padding:12px}
        label{display:block;margin-top:12px;font-size:14px;font-weight:700}.cover{width:100%;max-height:180px;object-fit:cover;border-radius:18px;margin-bottom:16px}.logo{width:74px;height:74px;border-radius:18px;object-fit:cover}
    </style>
</head>
<body>
    <div class="container">
        <div class="top">
            <div>
                <h1 style="margin:0"><?= h($config['nome_sistema']) ?></h1>
                <p class="small"><?= h($config['empresa']) ?> • Logado como <?= h($user['nome']) ?> (<?= h($user['tipo']) ?>)</p>
            </div>
            <a class="btn btn-gray" href="?action=logout">Sair</a>
        </div>

        <?php if (!empty($config['capa_url'])): ?>
            <img class="cover" src="<?= h($config['capa_url']) ?>" alt="Capa">
        <?php endif; ?>

        <div class="grid grid-4" style="margin-bottom:20px">
            <div class="card"><div class="small">Clientes</div><div class="metric"><?= $totalPessoas ?></div></div>
            <div class="card"><div class="small">Em aberto</div><div class="metric"><?= $totalAberto ?></div></div>
            <div class="card"><div class="small">Saldo</div><div class="metric" style="font-size:24px"><?= h(formatMoney($finance['saldo'])) ?></div></div>
            <div class="card"><div class="small">Já pagaram</div><div class="metric"><?= $totalPago ?></div></div>
        </div>

        <div class="tabs">
            <a class="tab <?= $tab === 'dashboard' ? 'active' : '' ?>" href="?tab=dashboard">Dashboard</a>
            <a class="tab <?= $tab === 'pessoas' ? 'active' : '' ?>" href="?tab=pessoas">Pessoas</a>
            <a class="tab <?= $tab === 'financas' ? 'active' : '' ?>" href="?tab=financas">Finanças</a>
            <?php if (isAdmin($user)): ?>
                <a class="tab <?= $tab === 'usuarios' ? 'active' : '' ?>" href="?tab=usuarios">Usuários</a>
            <?php endif; ?>
            <a class="tab <?= $tab === 'config' ? 'active' : '' ?>" href="?tab=config">Configurações</a>
        </div>

        <?php if ($tab === 'dashboard'): ?>
            <div class="grid grid-2">
                <div class="card">
                    <h2>Painel de Controle Financeiro</h2>
                    <p class="small">Dashboard de finanças pessoais e crediário</p>
                    <div class="grid grid-3" style="margin-top:14px">
                        <div class="mini"><div class="small">Entradas</div><h3><?= h(formatMoney($finance['entradas'])) ?></h3></div>
                        <div class="mini"><div class="small">Saídas</div><h3><?= h(formatMoney($finance['saidas'])) ?></h3></div>
                        <div class="mini"><div class="small">Saldo</div><h3><?= h(formatMoney($finance['saldo'])) ?></h3></div>
                    </div>
                </div>
                <div class="card">
                    <h2>Análise financeira com IA</h2>
                    <p class="small">Resumo inteligente baseado no banco de dados</p>
                    <div class="mini" style="margin-top:14px">
                        <p><b>Diagnóstico:</b> <?= h($finance['diagnostico']) ?></p>
                        <p><b>Sugestão:</b> <?= h($finance['sugestao']) ?></p>
                        <p><b>Maior gasto:</b>
                            <?= $finance['categoria'] ? h(($finance['categoria']['categoria'] ?: 'Outros') . ' - ' . formatMoney($finance['categoria']['total'])) : 'Nenhum' ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'pessoas'): ?>
            <div class="grid main">
                <div class="card">
                    <h2><?= $editPessoa ? 'Editar pessoa' : 'Cadastrar pessoa' ?></h2>
                    <form method="post">
                        <input type="hidden" name="action" value="save_pessoa">
                        <input type="hidden" name="id" value="<?= (int)($editPessoa['id'] ?? 0) ?>">
                        <label>Nome</label><input class="input" name="nome" value="<?= h($editPessoa['nome'] ?? '') ?>" required>
                        <label>Telefone</label><input class="input" name="telefone" value="<?= h($editPessoa['telefone'] ?? '') ?>">
                        <label>CPF</label><input class="input" name="cpf" value="<?= h($editPessoa['cpf'] ?? '') ?>">
                        <label>Endereço</label><input class="input" name="endereco" value="<?= h($editPessoa['endereco'] ?? '') ?>">
                        <label>Status da pessoa</label>
                        <select class="select" name="status_pessoa">
                            <option value="ativo" <?= (($editPessoa['status_pessoa'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                            <option value="inativo" <?= (($editPessoa['status_pessoa'] ?? '') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                        </select>
                        <label>Status do pagamento</label>
                        <select class="select" name="status_pagamento">
                            <option value="aberto" <?= (($editPessoa['status_pagamento'] ?? 'aberto') === 'aberto') ? 'selected' : '' ?>>Em aberto</option>
                            <option value="pagando" <?= (($editPessoa['status_pagamento'] ?? '') === 'pagando') ? 'selected' : '' ?>>Em pagamento</option>
                            <option value="pago" <?= (($editPessoa['status_pagamento'] ?? '') === 'pago') ? 'selected' : '' ?>>Já pagou</option>
                        </select>
                        <label>Data de entrega</label><input class="input" type="date" name="data_entrega" value="<?= h($editPessoa['data_entrega'] ?? '') ?>">
                        <label>Data para pagar</label><input class="input" type="date" name="data_pagamento" value="<?= h($editPessoa['data_pagamento'] ?? '') ?>">
                        <label>Valor</label><input class="input" type="number" step="0.01" name="valor" value="<?= h((string)($editPessoa['valor'] ?? '')) ?>">
                        <label>Observações</label><textarea class="textarea" name="observacoes"><?= h($editPessoa['observacoes'] ?? '') ?></textarea>
                        <div class="row" style="margin-top:14px">
                            <button class="btn btn-dark" type="submit"><?= $editPessoa ? 'Salvar alterações' : 'Cadastrar' ?></button>
                            <a class="btn btn-gray" href="?tab=pessoas">Limpar</a>
                        </div>
                    </form>
                </div>
                <div class="card">
                    <div class="space">
                        <h2>Lista de pessoas</h2>
                        <form method="get" class="row">
                            <input type="hidden" name="tab" value="pessoas">
                            <input class="input" style="min-width:260px" name="busca" value="<?= h($busca) ?>" placeholder="Buscar pessoa...">
                        </form>
                    </div>
                    <?php if (!$pessoas): ?>
                        <p class="small">Nenhuma pessoa cadastrada.</p>
                    <?php endif; ?>
                    <?php foreach ($pessoas as $p): $dias = daysUntil($p['data_pagamento']); ?>
                        <div class="item">
                            <div class="space">
                                <div>
                                    <h3 style="margin:0 0 6px 0"><?= h($p['nome']) ?></h3>
                                    <p class="small">CPF: <?= h($p['cpf']) ?: '-' ?> • Tel: <?= h($p['telefone']) ?: '-' ?></p>
                                    <p class="small">Endereço: <?= h($p['endereco']) ?: '-' ?></p>
                                </div>
                                <div class="row">
                                    <span class="badge <?= $p['status_pessoa'] === 'ativo' ? 'blue' : 'gray' ?>"><?= h(ucfirst($p['status_pessoa'])) ?></span>
                                    <span class="badge <?= $p['status_pagamento'] === 'pago' ? 'green' : ($p['status_pagamento'] === 'pagando' ? 'yellow' : 'red') ?>"><?= h($p['status_pagamento']) ?></span>
                                </div>
                            </div>
                            <div class="grid grid-4" style="margin-top:12px">
                                <div class="mini"><div class="small">Entrega</div><b><?= h(formatDateBr($p['data_entrega'])) ?></b></div>
                                <div class="mini"><div class="small">Pagamento</div><b><?= h(formatDateBr($p['data_pagamento'])) ?></b></div>
                                <div class="mini"><div class="small">Valor</div><b><?= h(formatMoney($p['valor'])) ?></b></div>
                                <div class="mini"><div class="small">Prazo</div><b>
                                    <?php if ($dias === null): ?>-
                                    <?php elseif ($dias < 0): ?><?= abs($dias) ?> dia(s) atrasado
                                    <?php elseif ($dias === 0): ?>Vence hoje
                                    <?php else: ?><?= $dias ?> dia(s) restantes<?php endif; ?>
                                </b></div>
                            </div>
                            <?php if (!empty($p['observacoes'])): ?>
                                <div class="mini" style="margin-top:10px"><div class="small">Observações</div><?= nl2br(h($p['observacoes'])) ?></div>
                            <?php endif; ?>
                            <p class="small" style="margin-top:10px">Criado em <?= h($p['created_at']) ?> • Atualizado em <?= h($p['updated_at']) ?></p>
                            <div class="row" style="margin-top:12px">
                                <a class="btn btn-gray" href="?tab=pessoas&edit_pessoa=<?= (int)$p['id'] ?>">Editar</a>
                                <a class="btn btn-yellow" href="?action=toggle_pessoa&id=<?= (int)$p['id'] ?>"><?= $p['status_pessoa'] === 'ativo' ? 'Inativar' : 'Ativar' ?></a>
                                <?php if (isAdmin($user)): ?>
                                    <a class="btn btn-red" href="?action=delete_pessoa&id=<?= (int)$p['id'] ?>" onclick="return confirm('Excluir esta pessoa?')">Excluir</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'financas'): ?>
            <div class="grid main">
                <div class="card">
                    <h2>Novo lançamento</h2>
                    <form method="post">
                        <input type="hidden" name="action" value="save_lancamento">
                        <label>Tipo</label>
                        <select class="select" name="tipo">
                            <option value="entrada">Entrada</option>
                            <option value="saida">Saída</option>
                        </select>
                        <label>Descrição</label><input class="input" name="descricao" required>
                        <label>Valor</label><input class="input" type="number" step="0.01" name="valor" required>
                        <label>Categoria</label><input class="input" name="categoria" placeholder="Ex: vendas, custos, aluguel">
                        <label>Data</label><input class="input" type="date" name="data_lancamento" value="<?= date('Y-m-d') ?>">
                        <button class="btn btn-dark" type="submit" style="margin-top:14px">Salvar lançamento</button>
                    </form>
                </div>
                <div class="card">
                    <h2>Dashboard de Finanças</h2>
                    <div class="grid grid-2" style="margin-top:14px">
                        <div>
                            <h3>Entradas</h3>
                            <?php if (!$entradas): ?><p class="small">Nenhuma entrada cadastrada.</p><?php endif; ?>
                            <?php foreach ($entradas as $l): ?>
                                <div class="item">
                                    <b><?= h($l['descricao']) ?></b>
                                    <p class="small"><?= h($l['categoria']) ?> • <?= h(formatDateBr($l['data_lancamento'])) ?></p>
                                    <p><?= h(formatMoney($l['valor'])) ?></p>
                                    <a class="btn btn-red" href="?action=delete_lancamento&id=<?= (int)$l['id'] ?>" onclick="return confirm('Excluir este lançamento?')">Excluir</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <h3>Saídas</h3>
                            <?php if (!$saidas): ?><p class="small">Nenhuma saída cadastrada.</p><?php endif; ?>
                            <?php foreach ($saidas as $l): ?>
                                <div class="item">
                                    <b><?= h($l['descricao']) ?></b>
                                    <p class="small"><?= h($l['categoria']) ?> • <?= h(formatDateBr($l['data_lancamento'])) ?></p>
                                    <p><?= h(formatMoney($l['valor'])) ?></p>
                                    <a class="btn btn-red" href="?action=delete_lancamento&id=<?= (int)$l['id'] ?>" onclick="return confirm('Excluir este lançamento?')">Excluir</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'usuarios' && isAdmin($user)): ?>
            <div class="grid main">
                <div class="card">
                    <h2><?= $editUser ? 'Editar usuário' : 'Criar login' ?></h2>
                    <form method="post">
                        <input type="hidden" name="action" value="save_user">
                        <input type="hidden" name="id" value="<?= (int)($editUser['id'] ?? 0) ?>">
                        <label>Nome</label><input class="input" name="nome" value="<?= h($editUser['nome'] ?? '') ?>" required>
                        <label>Login</label><input class="input" name="usuario" value="<?= h($editUser['usuario'] ?? '') ?>" required>
                        <label>Senha <?= $editUser ? '(deixe vazia para manter)' : '' ?></label><input class="input" name="senha" type="password">
                        <label>Tipo</label>
                        <select class="select" name="tipo">
                            <option value="admin" <?= (($editUser['tipo'] ?? '') === 'admin') ? 'selected' : '' ?>>Administrador</option>
                            <option value="usuario" <?= (($editUser['tipo'] ?? 'usuario') === 'usuario') ? 'selected' : '' ?>>Usuário</option>
                        </select>
                        <label><input type="checkbox" name="ativo" <?= !isset($editUser['ativo']) || (int)$editUser['ativo'] === 1 ? 'checked' : '' ?>> Conta ativa</label>
                        <div class="row" style="margin-top:14px">
                            <button class="btn btn-dark" type="submit"><?= $editUser ? 'Salvar usuário' : 'Criar conta' ?></button>
                            <a class="btn btn-gray" href="?tab=usuarios">Limpar</a>
                        </div>
                    </form>
                </div>
                <div class="card">
                    <h2>Usuários do sistema</h2>
                    <?php foreach ($users as $u): ?>
                        <div class="item">
                            <div class="space">
                                <div>
                                    <h3 style="margin:0 0 6px 0"><?= h($u['nome']) ?></h3>
                                    <p class="small">Login: <?= h($u['usuario']) ?></p>
                                    <p class="small">Criado em <?= h($u['created_at']) ?></p>
                                </div>
                                <div class="row">
                                    <span class="badge <?= $u['tipo'] === 'admin' ? 'blue' : 'gray' ?>"><?= h($u['tipo']) ?></span>
                                    <span class="badge <?= (int)$u['ativo'] === 1 ? 'green' : 'red' ?>"><?= (int)$u['ativo'] === 1 ? 'ativo' : 'inativo' ?></span>
                                </div>
                            </div>
                            <div class="row" style="margin-top:12px">
                                <a class="btn btn-gray" href="?tab=usuarios&edit_user=<?= (int)$u['id'] ?>">Editar</a>
                                <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                                    <a class="btn btn-red" href="?action=delete_user&id=<?= (int)$u['id'] ?>" onclick="return confirm('Excluir este usuário?')">Excluir</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'config'): ?>
            <div class="grid grid-2">
                <div class="card">
                    <h2>Personalizar o sistema</h2>
                    <form method="post">
                        <input type="hidden" name="action" value="save_config">
                        <label>Nome do sistema</label><input class="input" name="nome_sistema" value="<?= h($config['nome_sistema']) ?>">
                        <label>Empresa</label><input class="input" name="empresa" value="<?= h($config['empresa']) ?>">
                        <label>Saudação</label><input class="input" name="saudacao" value="<?= h($config['saudacao']) ?>">
                        <label>URL da logo</label><input class="input" name="logo_url" value="<?= h($config['logo_url']) ?>">
                        <label>URL da capa</label><input class="input" name="capa_url" value="<?= h($config['capa_url']) ?>">
                        <label>Meta de economia mensal</label><input class="input" type="number" step="0.01" name="meta_economia" value="<?= h((string)$config['meta_economia']) ?>">
                        <label>Limite de gastos</label><input class="input" type="number" step="0.01" name="limite_gastos" value="<?= h((string)$config['limite_gastos']) ?>">
                        <?php if (isAdmin($user)): ?>
                            <button class="btn btn-dark" type="submit" style="margin-top:14px">Salvar configurações</button>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="card">
                    <h2>Pré-visualização</h2>
                    <?php if (!empty($config['capa_url'])): ?><img class="cover" src="<?= h($config['capa_url']) ?>" alt="Capa"><?php endif; ?>
                    <?php if (!empty($config['logo_url'])): ?><img class="logo" src="<?= h($config['logo_url']) ?>" alt="Logo"><?php endif; ?>
                    <h3><?= h($config['nome_sistema']) ?></h3>
                    <p class="small"><?= h($config['empresa']) ?></p>
                    <div class="mini"><?= h($config['saudacao']) ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
