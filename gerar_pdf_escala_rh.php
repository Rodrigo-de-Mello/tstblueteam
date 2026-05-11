<?php
// gerar_pdf_escala_fpdf.php
session_start();
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

require_once 'conexao.php';

// Verificar se o usuário tem permissão
$stmt = $pdo->prepare("SELECT IS_ADMIN FROM usuarios WHERE userid = ?");
$stmt->execute([$_SESSION['userid']]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario || $usuario['IS_ADMIN'] != 1) {
    die('Acesso negado. Apenas administradores podem gerar PDF.');
}

// Obter mês e ano da URL
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');

// Array de meses em português
$meses_pt = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

// Buscar plantões do mês
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.nome as plantonista_nome 
        FROM plantoes p 
        JOIN usuarios u ON p.plantonista_id = u.userid 
        WHERE (MONTH(p.data_inicio) = ? AND YEAR(p.data_inicio) = ?)
        OR (MONTH(p.data_fim) = ? AND YEAR(p.data_fim) = ?)
        ORDER BY p.data_inicio
    ");
    $stmt->execute([$mes, $ano, $mes, $ano]);
    $plantoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao buscar plantões: " . $e->getMessage());
}

// Determinar o período total dos plantões
$menor_data_inicio = null;
$maior_data_fim = null;
$feriados_no_periodo = [];

if (!empty($plantoes)) {
    // Encontrar a menor data de início e maior data de término
    foreach ($plantoes as $plantao) {
        $data_inicio = strtotime($plantao['data_inicio']);
        $data_fim = strtotime($plantao['data_fim']);
        
        if ($menor_data_inicio === null || $data_inicio < $menor_data_inicio) {
            $menor_data_inicio = $data_inicio;
        }
        
        if ($maior_data_fim === null || $data_fim > $maior_data_fim) {
            $maior_data_fim = $data_fim;
        }
    }
    
    // Buscar todos os feriados que estão dentro do período total
    try {
        $stmt = $pdo->prepare("
            SELECT data_feriado, descricao 
            FROM feriados 
            WHERE data_feriado >= ? AND data_feriado <= ?
            ORDER BY data_feriado
        ");
        $stmt->execute([
            date('Y-m-d', $menor_data_inicio),
            date('Y-m-d', $maior_data_fim)
        ]);
        $feriados_no_periodo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Se der erro, continuar sem feriados
        $feriados_no_periodo = [];
    }
}

// Incluir FPDF
require('fpdf/fpdf.php');

class PDF extends FPDF
{
    // Cabeçalho
    function Header()
    {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, utf8_decode('ESCALA DE PLANTÃO'), 0, 1, 'C');
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, utf8_decode('Serviço de Informática'), 0, 1, 'C');
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 5, 'Telefone de Contato: 96420-0378.', 0, 1, 'C');
        $this->Ln(10);
    }
    
    // Rodapé
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' - Gerado em: ' . date('d/m/Y H:i:s'), 0, 0, 'C');
    }
    
    // Tabela básica
    function BasicTable($header, $data)
    {
        // Cabeçalho
        $this->SetFillColor(200, 220, 255);
        $this->SetTextColor(0);
        $this->SetDrawColor(0);
        $this->SetLineWidth(.3);
        $this->SetFont('', 'B');
        
        $w = array(45, 45, 60, 40);
        
        for($i = 0; $i < count($header); $i++) {
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Dados
        $this->SetFont('', '');
        $fill = false;
        
        foreach($data as $row) {
            $this->Cell($w[0], 6, $row[0], 'LR', 0, 'C', $fill);
            $this->Cell($w[1], 6, $row[1], 'LR', 0, 'C', $fill);
            $this->Cell($w[2], 6, $row[2], 'LR', 0, 'C', $fill);
            $this->Cell($w[3], 6, '', 'LR', 0, 'C', $fill);
            $this->Ln();
            $fill = !$fill;
        }
        
        $this->Cell(array_sum($w), 0, '', 'T');
    }
    
    // Adicionar seção de feriados
    function AdicionarFeriados($feriados)
    {
        if (!empty($feriados)) {
            $this->Ln(15);
            
            // Título da seção de feriados
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 10, utf8_decode('Feriados no Período:'), 0, 1);
            $this->SetFont('Arial', '', 10);
            
            foreach ($feriados as $feriado) {
                $data_formatada = date('d/m/Y', strtotime($feriado['data_feriado']));
                $this->Cell(0, 6, utf8_decode("$data_formatada - {$feriado['descricao']}"), 0, 1);
            }
        }
    }
}

// Criar PDF
$pdf = new PDF();
$pdf->AddPage();

// Adicionar título do mês
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $meses_pt[$mes] . ' / ' . $ano, 0, 1, 'C');
$pdf->Ln(5);

// Preparar dados da tabela
$header = array(utf8_decode('Início Plantão'), utf8_decode('Fim Plantão'), 'Nome Plantonista', 'Assinatura');
$data = array();

foreach($plantoes as $plantao) {
    $inicio = date('d/m/Y H:i', strtotime($plantao['data_inicio']));
    $fim = date('d/m/Y H:i', strtotime($plantao['data_fim']));
    $data[] = array($inicio, $fim, utf8_decode($plantao['plantonista_nome']), '');
}

if (empty($data)) {
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, utf8_decode('Nenhum plantão cadastrado para este mês.'), 0, 1, 'C');
} else {
    $pdf->BasicTable($header, $data);
}

// Adicionar seção de feriados
$pdf->AdicionarFeriados($feriados_no_periodo);

// Nome do arquivo
$filename = 'Escala_Plantao_' . $meses_pt[$mes] . '_' . $ano . '.pdf';

// Output
$pdf->Output('I', $filename);