<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$app->get('/vendas/{dataVenda}', function($dataVenda) use ($app) {

    //seta a data atual e o horario de 12:55 para comparacao com a data da venda das encomendas
    $dataVenda = \DateTime::createFromFormat('Y-m-d', $dataVenda, new \DateTimeZone('America/Sao_Paulo'));
    $dataVenda->setTime(12, 55);
    $dataAtual = new \DateTime('now', new \DateTimeZone('America/Sao_Paulo'));

    if($dataAtual <= $dataVenda) { //se a data for valida
        //executa a consulta da fornada
        $sql = "SELECT * FROM fornadas WHERE data_venda = ?";
        $fornada = $app['db']->fetchAssoc($sql, array((string)$dataVenda->format('Y-m-d')));

        if($fornada != null) {
            $idFornada = $fornada['id'];

            $sql = "SELECT DISTINCT(fp.produto) AS id_produto, p.descricao, p.foto, p.preco, t.tipo AS tipo_produto ";
            $sql .= "FROM fornadaxprodutos fp INNER JOIN produtos p ON fp.produto = p.id INNER JOIN tipos_produto t ON ";
            $sql .= "p.tipo_produto = t.id WHERE fp.fornada = ?";

            //seleciona os produtos disponiveis para venda na fornada
            $produtos = $app['db']->fetchAll($sql, array((int)$idFornada));
 
            for($i=0;$i<count($produtos);$i++) {
                $sql = "SELECT fp.sabor_brigadeiro AS id_sabor, sb.sabor, (fp.quantidade - (SELECT IF(SUM(quantidade) IS NULL, 0, SUM(quantidade)) ";
                $sql .= "AS quant FROM pedidos WHERE id_produto = fp.produto AND id_sabor = fp.sabor_brigadeiro AND id_fornada = fp.fornada AND status_pedido ";
                $sql .= "IN (1, 6, 7, 8, 9, 14))) AS quantity FROM fornadaxprodutos fp INNER JOIN sabores_brigadeiros sb ON fp.sabor_brigadeiro = sb.id ";
                $sql .= "WHERE fp.fornada = ? AND fp.produto = ?";

                $saboresXquantidades = $app['db']->fetchAll($sql, array((int)$idFornada, (int)$produtos[$i]['id_produto']));

                $produtos[$i]['detalhes'] = $saboresXquantidades;
            }

            return $app['twig']->render('pedido.html', array('fornada' => $fornada, 'produtos' => $produtos));
        } else
            return $app->redirect('/');
    } else
        return $app->redirect('/');

});

$app->post('/vendas/processar-venda/{dataVenda}', function(Request $request, $dataVenda) use ($app) {
    $ip = $app['request']->server->get('REMOTE_ADDR');

    $idFornada = intval($request->get('fornada-id'));

    $produtos = $request->get('produtos');

    $sabores = array();
    $quantidades = array();
    $order = array();
    $pedido = array();

    $total = 0;

    $dataFornada = $app['db']->fetchColumn("SELECT data_venda FROM fornadas WHERE id = ?", array($idFornada), 0);

    $restantes = array();

    if(is_array($produtos) && (count($produtos) > 0)) {
        foreach ($produtos as $idProduto) {

            $produto = $app['db']->fetchColumn("SELECT tp.tipo FROM tipos_produto tp INNER JOIN produtos p ON tp.id = p.tipo_produto WHERE p.id = ?", array($idProduto), 0);

            $sabores = $request->get('sabores_' . $idProduto);
            $quantidades = $request->get('quantidade_' . $idProduto);

            for($i=0;$i<count($sabores);$i++) {
                if(trim($sabores[$i]) != "" && intval($quantidades[$i]) != 0) {
                    $sql = "SELECT ((SELECT quantidade FROM fornadaxprodutos WHERE fornada = ? AND produto = ? AND sabor_brigadeiro = ?) - ";
                    $sql .= "(SELECT IFNULL(quantidade, 0) AS quant FROM pedidos WHERE id_produto = ? AND id_sabor = ? AND id_fornada = ? ";
                    $sql .= "AND status_pedido IN (1, 6, 7, 8, 9, 14))) AS restante";

                    $quantidadeRestante = $app['db']->fetchColumn($sql, array((int)$idFornada, (int)$idProduto, (int)$sabores[$i], (int)$idProduto, (int)$sabores[$i], (int)$idFornada), 0);

                    $restantes[] = $quantidadeRestante;

                    $sabor = $app['db']->fetchColumn("SELECT sabor FROM sabores_brigadeiros WHERE id = ?", array($sabores[$i]), 0);
                    $preco = $app['db']->fetchColumn("SELECT preco FROM produtos WHERE id = ?", array($idProduto), 0);
                    $pedido[$produto][$sabor]["quantidade"] = $quantidades[$i];

                    if(is_null($quantidadeRestante) || ( !is_null($quantidadeRestante) && ($quantidadeRestante >= intval($quantidades[$i])) )) {
                        $pedido[$produto][$sabor]["preco"] = number_format($quantidades[$i] * $preco, 2);
                        $order[] = array(1, intval($idProduto), intval($sabores[$i]), intval($idFornada), 1, intval($quantidades[$i]));
                        $total += $quantidades[$i]*$preco;
                    } else
                        $pedido[$produto][$sabor]["erro"] = intval($quantidades[$i])-$quantidadeRestante;
                }
            }

            $app['session']->set('order_items', $order);
        }
    }

    //return print_r($pedido, true);
    return $app['twig']->render('revisao-pedido.html', array('items' => $pedido, 'total' => number_format($total, 2), 'idFornada' => $idFornada, 'data_venda' => $dataVenda));

});

$app->get('/', function() use ($app) {
    return $app['twig']->render('inicial.html');
});
