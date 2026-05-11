<?php
session_start();
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

require_once 'conexao.php';
require_once('fpdf/fpdf.php');

if (!isset($_POST['plantao_id'])) {
    die("Plantão não especificado.");
}

$plantao_id = $_POST['plantao_id'];

// Buscar dados do plantão
$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.matricula, u.cargo, u.nome 
    FROM plantoes p 
    JOIN usuarios u ON p.plantonista_id = u.userid 
    WHERE p.id = ?
");
$stmt->execute([$plantao_id]);
$plantao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plantao) {
    die("Plantão não encontrado.");
}

// Buscar dados de sobreaviso
$stmt_sobreaviso = $pdo->prepare("
    SELECT * FROM sobreavisos 
    WHERE plantao_id = ? 
    ORDER BY ordem
");
$stmt_sobreaviso->execute([$plantao_id]);
$sobreavisos = $stmt_sobreaviso->fetchAll(PDO::FETCH_ASSOC);

// Buscar dados de horas extras manuais
$stmt_hora_extra = $pdo->prepare("
    SELECT * FROM horas_extras 
    WHERE plantao_id = ? 
    ORDER BY ordem
");
$stmt_hora_extra->execute([$plantao_id]);
$horas_extras_manuais = $stmt_hora_extra->fetchAll(PDO::FETCH_ASSOC);

// Buscar atendimentos com hora extra = Sim
$stmt_atendimentos = $pdo->prepare("
    SELECT 
        tipo,
        chamado,
        inicio_atendimento,
        fim_atendimento,
        tempo_total
    FROM atendimentos 
    WHERE plantao_id = ? AND hora_extra = 'Sim'
    ORDER BY inicio_atendimento
");
$stmt_atendimentos->execute([$plantao_id]);
$atendimentos_hora_extra = $stmt_atendimentos->fetchAll(PDO::FETCH_ASSOC);

// Converter atendimentos para o mesmo formato das horas extras manuais
$horas_extras_atendimentos = [];
foreach ($atendimentos_hora_extra as $index => $atendimento) {
    $horas_extras_atendimentos[] = [
        'tipo' => $atendimento['tipo'],
        'entrada' => date('d/m/Y H:i', strtotime($atendimento['inicio_atendimento'])),
        'saida' => date('d/m/Y H:i', strtotime($atendimento['fim_atendimento'])),
        'total' => $atendimento['tempo_total'],
        'origem' => 'Atendimento',
        'chamado' => $atendimento['chamado']
    ];
}

// Combinar horas extras manuais e de atendimentos
$todas_horas_extras = array_merge($horas_extras_manuais, $horas_extras_atendimentos);

// Ordenar por data/hora de entrada
usort($todas_horas_extras, function($a, $b) {
    $entradaA = DateTime::createFromFormat('d/m/Y H:i', $a['entrada']);
    $entradaB = DateTime::createFromFormat('d/m/Y H:i', $b['entrada']);
    
    if ($entradaA == $entradaB) {
        return 0;
    }
    return ($entradaA < $entradaB) ? -1 : 1;
});

// Determinar o mês de referência (mês do início do plantão)
$data_inicio_plantao = new DateTime($plantao['data_inicio']);
$mes_referencia = $data_inicio_plantao->format('Y-m');
$ano_mes = $data_inicio_plantao->format('mmm-Y');



// Calcular totais
$total_sobreaviso = calcularTotalSobreaviso($sobreavisos);
$total_hora_extra = calcularTotalHoraExtra($todas_horas_extras);
$dsr_hora_extra = calcularDSRHoraExtra($total_hora_extra, $mes_referencia, $pdo);
$total_pagar = calcularTotalPagar($total_sobreaviso, $total_hora_extra, $dsr_hora_extra);


// Criar PDF com layout específico
class PDF_RH extends FPDF
{
    private $headerColor = array(0, 51, 102); // Azul escuro
    private $accentColor = array(0, 102, 204); // Azul
    private $mediumColor = array(192, 192, 192); // Cinza medio
    private $lightColor = array(240, 240, 240); // Cinza claro

    function Header()
    {
        // Cabeçalho com cor e borda
        $this->SetFillColor($this->mediumColor[0], $this->mediumColor[1], $this->mediumColor[2]);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, 'PAGAMENTO SOBRE AVISO E HORAS EXTRAS', 1, 1, 'C', true); // Adicionada borda
        $this->Ln(5);
        
        // Resetar cor do texto
        $this->SetTextColor(0, 0, 0);
    }
    
    function Footer()
    {
        //$this->SetY(-15);
        //$this->SetFont('Arial', 'I', 8);
        //$this->SetTextColor(128, 128, 128);
        //$this->Cell(0, 10, 'Página ' . $this->PageNo() . ' - Gerado em ' . date('d/m/Y H:i'), 0, 0, 'C');
    }

    function AddDadosPlantonista($plantao)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor($this->lightColor[0], $this->lightColor[1], $this->lightColor[2]);
        $this->Cell(0, 10, 'DADOS DO PLANTONISTA', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 8);
        $this->Cell(95, 8, 'Nome: ' . (utf8_decode($plantao['nome']) ?? $plantao['username']), 1, 0);
        $this->Cell(95, 8, utf8_decode('Matrícula: ') . $plantao['matricula'], 1, 1);
        
        $this->Cell(95, 8, 'Cargo: ' . utf8_decode($plantao['cargo']), 1, 0);
        $this->Cell(95, 8, 'Uniorg: 310000', 1, 1);
        
        $this->Cell(95, 8, utf8_decode('Período: ') . date('d/m/Y', strtotime($plantao['data_inicio'])) . ' a ' . date('d/m/Y', strtotime($plantao['data_fim'])), 1, 0);
        //$this->Cell(95, 8, 'Folha: ' . date('F Y', strtotime($plantao['data_inicio'])), 1, 1);
        $this->Cell(95, 8, 'Folha: ' . $this->formatarMesPortugues($plantao['data_inicio']), 1, 1);

        $this->Ln(10);
    }

    function AddSobreavisoTable($sobreavisos, $total)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor($this->lightColor[0], $this->lightColor[1], $this->lightColor[2]);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 10, 'REGISTROS DE SOBREAVISO', 1, 1, 'C', true); // Agora com borda para ocupar mesma largura
        
        // Cabeçalho da tabela com larguras ajustadas
        $this->SetFont('Arial', 'B', 7); // Fonte menor para caber todas as colunas
        $this->SetFillColor($this->lightColor[0], $this->lightColor[1], $this->lightColor[2]);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(35, 8, 'DATA', 1, 0, 'C', true);
        $this->Cell(30, 8, 'ENTRADA1', 1, 0, 'C', true);
        $this->Cell(30, 8, utf8_decode('SAÍDA1'), 1, 0, 'C', true);
        $this->Cell(30, 8, 'ENTRADA2', 1, 0, 'C', true);
        $this->Cell(30, 8, utf8_decode('SAÍDA2'), 1, 0, 'C', true);
        $this->Cell(35, 8, 'TOTAL', 1, 1, 'C', true);
        
        // Dados
        $this->SetFont('Arial', '', 7);
        $fill = false;
        foreach ($sobreavisos as $sobreaviso) {
            $this->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);
            $this->Cell(35, 7, $sobreaviso['data'], 1, 0, 'C', $fill);
            $this->Cell(30, 7, $sobreaviso['entrada1'] ?? '', 1, 0, 'C', $fill);
            $this->Cell(30, 7, $sobreaviso['saida1'] ?? '', 1, 0, 'C', $fill);
            $this->Cell(30, 7, $sobreaviso['entrada2'] ?? '', 1, 0, 'C', $fill);
            $this->Cell(30, 7, $sobreaviso['saida2'] ?? '', 1, 0, 'C', $fill);
            $this->Cell(35, 7, $sobreaviso['total'], 1, 1, 'C', $fill);
            $fill = !$fill;
        }
        
        // Total
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor($this->lightColor[0], $this->lightColor[1], $this->lightColor[2]);
        $this->Cell(155, 8, 'TOTAL DE SOBREAVISO', 1, 0, 'R', true); // 25+20+20+20+20 = 105
        $this->Cell(35, 8, $total, 1, 1, 'C', true);
        $this->Ln(8);
    }

    function AddHoraExtraTable($horas_extras, $total_he, $dsr, $total_pagar)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor($this->lightColor[0], $this->lightColor[1], $this->lightColor[2]);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 10, 'REGISTROS DE HORA EXTRA', 1, 1, 'C', true); // Agora com borda
        
        // Cabeçalho da tabela com larguras ajustadas
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor($this->lightColor[0], $this->lightColor[1], $this->lightColor[2]);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(25, 8, 'TIPO', 1, 0, 'C', true);
        $this->Cell(25, 8, 'ORIGEM', 1, 0, 'C', true);
        $this->Cell(15, 8, 'CHAMADO', 1, 0, 'C', true);
        $this->Cell(45, 8, 'ENTRADA', 1, 0, 'C', true);
        $this->Cell(45, 8, utf8_decode('SAÍDA'), 1, 0, 'C', true);
        $this->Cell(35, 8, 'TOTAL', 1, 1, 'C', true);
        
        // Dados
        $this->SetFont('Arial', '', 7);
        $fill = false;
        foreach ($horas_extras as $hora_extra) {
            $this->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);
            
            // Tipo
            $this->Cell(25, 7, $hora_extra['tipo'] ?? '', 1, 0, 'C', $fill);
            
            // Origem
            $origem = isset($hora_extra['origem']) ? $hora_extra['origem'] : '-'; //texto do origem
            $this->Cell(25, 7, utf8_decode($origem), 1, 0, 'C', $fill);
            
            // Chamado (se for atendimento)
            $chamado = isset($hora_extra['chamado']) ? $hora_extra['chamado'] : '-';
            $this->Cell(15, 7, $chamado, 1, 0, 'C', $fill);
            
            // Entrada e Saída
            $this->Cell(45, 7, $hora_extra['entrada'] ?? '', 1, 0, 'C', $fill);
            $this->Cell(45, 7, $hora_extra['saida'] ?? '', 1, 0, 'C', $fill);
            
            // Total
            $this->Cell(35, 7, $hora_extra['total'] ?? '', 1, 1, 'C', $fill);
            $fill = !$fill;
        }
        
        // Totais
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor($this->lightColor[0], $this->lightColor[1], $this->lightColor[2]);
        $this->Cell(155, 8, 'Verba 209 - Total de Hora Extra', 1, 0, 'R', true);
        $this->Cell(35, 8, $total_he, 1, 1, 'C', true);
        
        $this->Cell(155, 8, 'Verba 184 - DSR de Hora Extra', 1, 0, 'R', true);
        $this->Cell(35, 8, $dsr, 1, 1, 'C', true);
        
        $this->SetFillColor(220, 230, 241); // Azul bem claro
        $this->Cell(155, 8, 'TOTAL A PAGAR SOBREAVISO', 1, 0, 'R', true);
        $this->Cell(35, 8, $total_pagar, 1, 1, 'C', true);
    }
        
    function AddAssinatura($nome)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->Ln(10);
        $this->Cell(0, 8, '________________________________________________', 0, 1, 'C');
        $this->Cell(0, 8, $nome, 0, 1, 'C');
        $this->Cell(0, 6, 'Data: ' . date('d/m/Y'), 0, 1, 'C');
    }

    function formatarMesPortugues($data)
    {
        $meses = [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro'
        ];
        
        $mes_numero = (int)date('n', strtotime($data));
        $ano = date('Y', strtotime($data));
        
        return $meses[$mes_numero] . ' de ' . $ano;
    }
}

// Criar PDF
$pdf = new PDF_RH();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// Adicionar conteúdo
$pdf->AddDadosPlantonista($plantao);

if (!empty($sobreavisos)) {
    $pdf->AddSobreavisoTable($sobreavisos, $total_sobreaviso);
}

if (!empty($todas_horas_extras)) {
    $pdf->AddHoraExtraTable($todas_horas_extras, $total_hora_extra, $dsr_hora_extra, $total_pagar);
}

$pdf->AddAssinatura($plantao['nome'] ?? $plantao['username']);

// Saída do PDF
$pdf->Output('I', 'RELATORIO_RH_' . date('d_m_Y', strtotime($plantao['data_inicio'])) . '_a_' . date('d_m_Y', strtotime($plantao['data_fim'])) . '.pdf');

// Funções auxiliares
function calcularTotalSobreaviso($sobreavisos) {
    $total_minutos = 0;
    foreach ($sobreavisos as $sv) {
        if (isset($sv['total']) && !empty($sv['total'])) {
            list($horas, $minutos, $segundos) = explode(':', $sv['total']);
            $total_minutos += ($horas * 60) + $minutos;
        }
    }
    $horas = floor($total_minutos / 60);
    $minutos = $total_minutos % 60;
    return sprintf("%02d:%02d:00", $horas, $minutos);
}

function calcularTotalHoraExtra($horas_extras) {
    $total_minutos = 0;
    foreach ($horas_extras as $he) {
        if (isset($he['total']) && !empty($he['total'])) {
            list($horas, $minutos, $segundos) = explode(':', $he['total']);
            $total_minutos += ($horas * 60) + $minutos;
        }
    }
    $horas = floor($total_minutos / 60);
    $minutos = $total_minutos % 60;
    return sprintf("%02d:%02d:00", $horas, $minutos);
}

function calcularDSRHoraExtra($total_he, $mes_referencia, $pdo) {
    // Extrair ano e mês
    $ano_mes = $mes_referencia; // formato 'Y-m'
    
    // Primeiro dia do mês
    $primeiro_dia = $ano_mes . '-01';
    $data = new DateTime($primeiro_dia);
    
    // Total de dias no mês
    $total_dias_mes = $data->format('t');
    
    // Contar domingos no mês
    $domingos = 0;
    $ano = $data->format('Y');
    $mes = $data->format('m');
    
    for ($dia = 1; $dia <= $total_dias_mes; $dia++) {
        $data_dia = new DateTime("$ano-$mes-$dia");
        if ($data_dia->format('w') == 0) { // 0 = domingo
            $domingos++;
        }
    }
    
    // Buscar feriados do mês
    $stmt_feriados = $pdo->prepare("
        SELECT COUNT(*) as total_feriados 
        FROM feriados 
        WHERE YEAR(data_feriado) = ? AND MONTH(data_feriado) = ?
    ");
    $stmt_feriados->execute([$ano, $mes]);
    $resultado = $stmt_feriados->fetch(PDO::FETCH_ASSOC);
    $feriados_count = $resultado['total_feriados'];
    
    // Total de domingos e feriados
    $total_domingos_feriados = $domingos + $feriados_count;
    
    // Dias úteis no mês
    $dias_uteis = $total_dias_mes - $total_domingos_feriados;
    
    // Se não houver dias úteis (situação incomum), retornar 00:00:00
    if ($dias_uteis <= 0) {
        return "00:00:00";
    }
    
    // Converter total de horas extras para minutos
    list($horas, $minutos, $segundos) = explode(':', $total_he);
    $total_minutos_he = ($horas * 60) + $minutos;
    
    // Calcular DSR: (total horas extras / dias úteis) * domingos e feriados
    $minutos_dsr = ($total_minutos_he / $dias_uteis) * $total_domingos_feriados;
    
    // Converter minutos de volta para horas:minutos
    $horas_dsr = floor($minutos_dsr / 60);
    $minutos_dsr = round($minutos_dsr % 60);
    
    // Se minutos_dsr for 60, ajustar para horas
    if ($minutos_dsr == 60) {
        $horas_dsr += 1;
        $minutos_dsr = 0;
    }
    
    return sprintf("%02d:%02d:00", $horas_dsr, $minutos_dsr);
}

function calcularTotalPagar($total_sobreaviso, $total_hora_extra, $dsr_hora_extra) {
    // Converter tudo para minutos
    list($h_sv, $m_sv, $s_sv) = explode(':', $total_sobreaviso);
    list($h_he, $m_he, $s_he) = explode(':', $total_hora_extra);
    list($h_dsr, $m_dsr, $s_dsr) = explode(':', $dsr_hora_extra);
    
    $total_sv_minutos = ($h_sv * 60) + $m_sv;
    $total_he_minutos = ($h_he * 60) + $m_he;
    $total_dsr_minutos = ($h_dsr * 60) + $m_dsr;
    
    // Total de horas extras com DSR
    $total_horas_extras_com_dsr = $total_he_minutos ;
    
    // Subtrair horas extras + DSR do sobreaviso
    $total_pagar_minutos = $total_sv_minutos - $total_horas_extras_com_dsr;
    
    // Se for negativo, retorna zero
    if ($total_pagar_minutos < 0) {
        $total_pagar_minutos = 0;
    }
    
    $horas = floor($total_pagar_minutos / 60);
    $minutos = $total_pagar_minutos % 60;
    
    return sprintf("%02d:%02d:00", $horas, $minutos);
}

?>