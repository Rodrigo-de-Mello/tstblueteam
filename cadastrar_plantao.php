<?php
// cadastrar_plantao.php
session_start();
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

require_once 'conexao.php';

// Buscar plantonistas (usuários com plantonista=1 e status=1)
try {
    $stmt = $pdo->prepare("SELECT userid, username FROM usuarios WHERE plantonista = 1 AND status = 1 ORDER BY username");
    $stmt->execute();
    $plantonistas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $plantonistas = [];
    error_log("Erro ao buscar plantonistas: " . $e->getMessage());
}

// Processar dados do formulário
if ($_POST && isset($_POST['plantonista_id']) && isset($_POST['data_inicio']) && isset($_POST['data_fim'])) {
    $plantonista_id = $_POST['plantonista_id'];
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    
    try {
        // Verificar se existe plantão em sequência para o mesmo plantonista
        $stmt = $pdo->prepare("
            SELECT id, data_inicio, data_fim 
            FROM plantoes 
            WHERE plantonista_id = ? 
            AND (
                (? BETWEEN data_inicio AND data_fim) OR
                (? BETWEEN data_inicio AND data_fim) OR
                (data_inicio BETWEEN ? AND ?) OR
                (data_fim BETWEEN ? AND ?)
            )
        ");
        $stmt->execute([
            $plantonista_id,
            $data_inicio, $data_fim,
            $data_inicio, $data_fim,
            $data_inicio, $data_fim
        ]);
        $plantao_conflitante = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($plantao_conflitante) {
            // Plantão em conflito encontrado - armazenar dados na sessão para confirmação
            $_SESSION['plantao_pendente'] = [
                'plantonista_id' => $plantonista_id,
                'data_inicio' => $data_inicio,
                'data_fim' => $data_fim,
                'plantao_conflitante' => $plantao_conflitante
            ];
            
            // Redirecionar para página de confirmação
            header("Location: confirmar_plantao.php");
            exit;
        } else {
            // Não há conflito, inserir normalmente
            $stmt = $pdo->prepare("INSERT INTO plantoes (plantonista_id, data_inicio, data_fim) VALUES (?, ?, ?)");
            $stmt->execute([$plantonista_id, $data_inicio, $data_fim]);
            $mensagem_sucesso = "Plantão cadastrado com sucesso!";
        }
        
    } catch (PDOException $e) {
        $mensagem_erro = "Erro ao cadastrar plantão: " . $e->getMessage();
        error_log("Erro ao inserir plantão: " . $e->getMessage());
    }
}

// PARÂMETROS DE PAGINAÇÃO
$itens_por_pagina = isset($_GET['itens']) && in_array($_GET['itens'], [25, 50, 100]) ? (int)$_GET['itens'] : 25;
$pagina_atual_num = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_atual_num - 1) * $itens_por_pagina;

// Contar total de plantões
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM plantoes");
    $stmt->execute();
    $total_plantoes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_paginas = ceil($total_plantoes / $itens_por_pagina);
    
    // Ajustar página atual se for maior que o total de páginas
    if ($pagina_atual_num > $total_paginas && $total_paginas > 0) {
        $pagina_atual_num = $total_paginas;
        $offset = ($pagina_atual_num - 1) * $itens_por_pagina;
    }
} catch (PDOException $e) {
    $total_plantoes = 0;
    $total_paginas = 1;
    error_log("Erro ao contar plantões: " . $e->getMessage());
}

// Buscar plantões com paginação
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username 
        FROM plantoes p 
        JOIN usuarios u ON p.plantonista_id = u.userid 
        ORDER BY p.data_inicio DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $itens_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $plantoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $plantoes = [];
    error_log("Erro ao buscar plantões: " . $e->getMessage());
}

// Buscar última data de término para preenchimento automático
try {
    $stmt = $pdo->prepare("SELECT data_fim FROM plantoes ORDER BY data_fim DESC LIMIT 1");
    $stmt->execute();
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    $ultima_data_fim = $resultado['data_fim'] ?? null;
} catch (PDOException $e) {
    $ultima_data_fim = null;
    error_log("Erro ao buscar última data de término: " . $e->getMessage());
}

// Buscar última atualização para o sidebar
try {
    $stmt = $pdo->prepare("SELECT MAX(created_at) as ultima_atualizacao FROM plantoes");
    $stmt->execute();
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    $ultima_atualizacao = $resultado['ultima_atualizacao'] ? date('d/m/Y H:i', strtotime($resultado['ultima_atualizacao'])) : 'N/D';
} catch (PDOException $e) {
    $ultima_atualizacao = 'Erro';
    error_log("Erro ao buscar última atualização: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Cadastro de Plantões</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .badge.bg-secondary {
            background: linear-gradient(135deg, #6c757d, #495057) !important;
            font-size: 0.8rem;
            padding: 5px 10px;
        }
        .sidebar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            height: 100vh;
            position: fixed;
            width: 250px;
            display: flex;
            flex-direction: column;
        }
        .sidebar .position-sticky {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .sidebar .nav {
            flex: 1;
        }
        .sidebar .border-top {
            border-top: 1px solid rgba(255,255,255,0.2) !important;
            padding-top: 15px;
            margin-top: auto;
        }
        .sidebar a {
            color: white;
            text-decoration: none;
            padding: 15px;
            display: block;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar a:hover {
            background: rgba(255,255,255,0.1);
        }
        .sidebar a.active {
            background: rgba(255,255,255,0.2);
            border-left: 4px solid #fff;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-bottom: 1px solid #e3e6f0;
            border-radius: 15px 15px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .table-actions {
            white-space: nowrap;
        }
        
        .plantao-conflito {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107;
        }
        
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 10px 0;
        }
        
        .items-per-page {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .pagination .page-item.active .page-link {
            background-color: #667eea;
            border-color: #667eea;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php 
            // Determinar qual página está ativa para o sidebar
            $pagina_sidebar = basename($_SERVER['PHP_SELF']);
            include 'sidebar.php'; 
            ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Cadastro de Plantões
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-primary" id="btnNovoPlantao" data-bs-toggle="modal" data-bs-target="#modalCadastro">
                            <i class="fas fa-plus me-1"></i> Novo Plantão
                        </button>
                    </div>
                </div>

                <!-- Alertas -->
                <?php if (isset($mensagem_sucesso)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $mensagem_sucesso ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($mensagem_erro)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= $mensagem_erro ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Formulário de cadastro (modal) -->
                <div class="modal fade" id="modalCadastro" tabindex="-1" aria-labelledby="modalCadastroLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalCadastroLabel">Cadastrar Plantão</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="plantonista_id" class="form-label">Plantonista</label>
                                        <select class="form-select" id="plantonista_id" name="plantonista_id" required>
                                            <option value="">Selecione um plantonista</option>
                                            <?php foreach ($plantonistas as $plantonista): ?>
                                                <option value="<?= $plantonista['userid'] ?>"><?= htmlspecialchars($plantonista['username']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="data_inicio" class="form-label">Data e Hora de Início</label>
                                        <input type="datetime-local" class="form-control" id="data_inicio" name="data_inicio" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="data_fim" class="form-label">Data e Hora de Fim</label>
                                        <input type="datetime-local" class="form-control" id="data_fim" name="data_fim" required>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" class="btn btn-primary">Salvar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Tabela de resumo com plantões paginados -->
                <div class="table-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">
                            <i class="fas fa-list me-2"></i>
                            Todos os Plantões Cadastrados
                            <span class="badge bg-primary ms-2"><?= $total_plantoes ?></span>
                        </h4>
                        <div class="items-per-page">
                            <span class="pagination-info">
                                Mostrando <?= count($plantoes) ?> de <?= $total_plantoes ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Plantonista</th>
                                    <th>Data de Início</th>
                                    <th>Data de Fim</th>
                                    <th>Dias de Plantão</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plantoes as $plantao): ?>
                                    <?php
                                    $inicio = new DateTime($plantao['data_inicio']);
                                    $fim = new DateTime($plantao['data_fim']);
                                    $dias_plantao = $inicio->diff($fim)->days + 1;
                                    
                                    // Verificar se há conflito com outros plantões (apenas na página atual)
                                    $tem_conflito = false;
                                    foreach ($plantoes as $outro_plantao) {
                                        if ($plantao['id'] != $outro_plantao['id'] && 
                                            $plantao['plantonista_id'] == $outro_plantao['plantonista_id'] &&
                                            (
                                                ($plantao['data_inicio'] >= $outro_plantao['data_inicio'] && $plantao['data_inicio'] <= $outro_plantao['data_fim']) ||
                                                ($plantao['data_fim'] >= $outro_plantao['data_inicio'] && $plantao['data_fim'] <= $outro_plantao['data_fim']) ||
                                                ($plantao['data_inicio'] <= $outro_plantao['data_inicio'] && $plantao['data_fim'] >= $outro_plantao['data_fim'])
                                            )
                                        ) {
                                            $tem_conflito = true;
                                            break;
                                        }
                                    }
                                    ?>
                                    <tr class="<?= $tem_conflito ? 'plantao-conflito' : '' ?>">
                                        <td>
                                            <?= htmlspecialchars($plantao['username']) ?>
                                            <?php if ($tem_conflito): ?>
                                                <span class="badge bg-warning ms-1" title="Plantão com conflito de datas">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('d/m/Y H:i', strtotime($plantao['data_inicio'])) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($plantao['data_fim'])) ?></td>
                                        <td><?= $dias_plantao ?> dia(s)</td>
                                        <td class="table-actions">
                                            <button class="btn btn-sm btn-outline-danger" onclick="excluirPlantao(<?= $plantao['id'] ?>, '<?= htmlspecialchars($plantao['username']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($plantoes)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">Nenhum plantão cadastrado</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Controles de paginação -->
                    <div class="pagination-container">
                        <div class="items-per-page">
                            <label for="itens_por_pagina" class="form-label mb-0">Itens por página:</label>
                            <select class="form-select form-select-sm" id="itens_por_pagina" style="width: auto;">
                                <option value="25" <?= $itens_por_pagina == 25 ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $itens_por_pagina == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $itens_por_pagina == 100 ? 'selected' : '' ?>>100</option>
                            </select>
                        </div>
                        
                        <?php if ($total_paginas > 1): ?>
                        <nav aria-label="Navegação de páginas">
                            <ul class="pagination mb-0">
                                <!-- Botão Anterior -->
                                <li class="page-item <?= $pagina_atual_num <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $pagina_atual_num - 1 ?>&itens=<?= $itens_por_pagina ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <!-- Páginas -->
                                <?php 
                                // Calcular range de páginas para mostrar
                                $inicio = max(1, $pagina_atual_num - 2);
                                $fim = min($total_paginas, $pagina_atual_num + 2);
                                
                                // Ajustar se estiver no início
                                if ($inicio == 1) {
                                    $fim = min($total_paginas, 5);
                                }
                                
                                // Ajustar se estiver no final
                                if ($fim == $total_paginas) {
                                    $inicio = max(1, $total_paginas - 4);
                                }
                                
                                // Primeira página
                                if ($inicio > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=1&itens=<?= $itens_por_pagina ?>">1</a>
                                </li>
                                <?php if ($inicio > 2): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                                <?php endif; ?>
                                <?php endif; ?>
                                
                                <!-- Páginas do meio -->
                                <?php for ($i = $inicio; $i <= $fim; $i++): ?>
                                <li class="page-item <?= $i == $pagina_atual_num ? 'active' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $i ?>&itens=<?= $itens_por_pagina ?>"><?= $i ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <!-- Última página -->
                                <?php if ($fim < $total_paginas): ?>
                                <?php if ($fim < $total_paginas - 1): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=<?= $total_paginas ?>&itens=<?= $itens_por_pagina ?>"><?= $total_paginas ?></a>
                                </li>
                                <?php endif; ?>
                                
                                <!-- Botão Próximo -->
                                <li class="page-item <?= $pagina_atual_num >= $total_paginas ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $pagina_atual_num + 1 ?>&itens=<?= $itens_por_pagina ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        
                        <div class="pagination-info">
                            Página <?= $pagina_atual_num ?> de <?= $total_paginas ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal de confirmação para exclusão -->
    <div class="modal fade" id="modalExcluir" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Exclusão</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja excluir este plantão?</p>
                    <p class="text-muted" id="detalhesPlantaoExcluir"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarExclusao">Excluir</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let plantaoParaExcluir = null;
        
        function excluirPlantao(id, nome) {
            plantaoParaExcluir = id;
            document.getElementById('detalhesPlantaoExcluir').textContent = `Plantonista: ${nome}`;
            new bootstrap.Modal(document.getElementById('modalExcluir')).show();
        }
        
        document.getElementById('btnConfirmarExclusao').addEventListener('click', function() {
            if (plantaoParaExcluir) {
                window.location.href = 'excluir_plantao.php?id=' + plantaoParaExcluir;
            }
        });
        
        // Função para preencher datas automaticamente
        function preencherDatasAutomaticamente() {
            <?php if ($ultima_data_fim): ?>
                // Usar a última data de término do PHP
                const ultimaDataFim = new Date('<?= $ultima_data_fim ?>');
                
                // Data de início: última data de término com horário 19:00
                const dataInicio = new Date(ultimaDataFim);
                dataInicio.setHours(19, 0, 0, 0);
                
                // Data de término: data de início + 7 dias com horário 07:00
                const dataFim = new Date(dataInicio);
                dataFim.setDate(dataFim.getDate() + 7);
                dataFim.setHours(7, 0, 0, 0);
                
                // Formatar para o input datetime-local (YYYY-MM-DDTHH:MM)
                const formatarData = (date) => {
                    const pad = (n) => n < 10 ? '0' + n : n;
                    return date.getFullYear() + '-' + 
                           pad(date.getMonth() + 1) + '-' + 
                           pad(date.getDate()) + 'T' + 
                           pad(date.getHours()) + ':' + 
                           pad(date.getMinutes());
                };
                
                document.getElementById('data_inicio').value = formatarData(dataInicio);
                document.getElementById('data_fim').value = formatarData(dataFim);
            <?php else: ?>
                // Se não houver último plantão, usar datas padrão
                const agora = new Date();
                const dataInicio = new Date(agora);
                dataInicio.setHours(19, 0, 0, 0);
                
                const dataFim = new Date(dataInicio);
                dataFim.setDate(dataFim.getDate() + 7);
                dataFim.setHours(7, 0, 0, 0);
                
                const formatarData = (date) => {
                    const pad = (n) => n < 10 ? '0' + n : n;
                    return date.getFullYear() + '-' + 
                           pad(date.getMonth() + 1) + '-' + 
                           pad(date.getDate()) + 'T' + 
                           pad(date.getHours()) + ':' + 
                           pad(date.getMinutes());
                };
                
                document.getElementById('data_inicio').value = formatarData(dataInicio);
                document.getElementById('data_fim').value = formatarData(dataFim);
            <?php endif; ?>
        }
        
        // Quando o botão "Novo Plantão" for clicado
        document.getElementById('btnNovoPlantao').addEventListener('click', function() {
            preencherDatasAutomaticamente();
        });
        
        // Quando o modal for aberto via Bootstrap
        const modalCadastro = document.getElementById('modalCadastro');
        if (modalCadastro) {
            modalCadastro.addEventListener('shown.bs.modal', function() {
                preencherDatasAutomaticamente();
            });
        }
        
        // Limpar formulário quando o modal for fechado
        if (modalCadastro) {
            modalCadastro.addEventListener('hidden.bs.modal', function() {
                document.getElementById('plantonista_id').value = '';
                // Não limpar as datas, pois serão preenchidas novamente
            });
        }
        
        // Controle de itens por página
        document.getElementById('itens_por_pagina').addEventListener('change', function() {
            const itens = this.value;
            window.location.href = `?pagina=1&itens=${itens}`;
        });
        
        // Fechar modal ao pressionar ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                plantaoParaExcluir = null;
            }
        });
    </script>
</body>
</html>