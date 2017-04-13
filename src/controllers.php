<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Silex\Provider\FormServiceProvider;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Security\Core\User\User as AdvancedUser;


$app->before(function () use ($app) {
    $app['translator']->addLoader('xlf', new Symfony\Component\Translation\Loader\XliffFileLoader());
    $app['translator']->addResource('xlf', __DIR__.'/vendor/symfony/validator/Symfony/Component/Validator/Resources/translations/validators/validators.sr_Latn.xlf', 'sr_Latn', 'validators');
});

$login = function(Request $request) use ($app) {
    $referer = $request->headers->get('referer');

    $app['session']->set('old-referer', (strpos($referer, 'vendas') !== false)?'/cliente/confirmar-venda/' . substr($referer, strrpos($referer, '/')+1):'');

    return $app['twig']->render('login_.html', array(
        'error' => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username')
    ));
};

$app->get('/login', $login);

$app->mount('/cliente/login/redirect', new App\Controller\LoginRedirect());

/* clientes */

$app->match('/novo-cliente', function (Request $request) use ($app) {

    $form = $app['form.factory']->createBuilder('form')
        ->add('nome', 'text', array('attr' => array('placeholder' => 'Nome completo'),
                                    'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 3)))
                                ))
        ->add('email', 'email', array('label' => 'E-mail', 'attr' => array('placeholder' => 'Seu e-mail'), 'constraints' => new Assert\Email()))
        ->add('senha', 'password', array('attr' => array('placeholder' => 'Defina uma senha'),
                                        'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 8)))
                                ))
        ->add('telefone', 'text', array('attr' => array('placeholder' => 'Telefone residencial', 'data-mask' => '(00)0000-0000')))
        ->add('celular', 'text', array('attr' => array('placeholder' => 'Telefone celular')))
        ->add('endereco', 'text', array('attr' => array('placeholder' => 'Rua e numero da casa/apartamento')))
        ->add('bairro', 'text', array('attr' => array('placeholder' => 'Bairro que voce mora')))
        ->add('cidade', 'text', array('attr' => array('placeholder' => 'Cidade que voce mora')))
        ->add('estado', ChoiceType::class, array(
            'choices' => array(""=>"","AC"=>"Acre", "AL"=>"Alagoas", "AM"=>"Amazonas", "AP"=>"Amapa","BA"=>"Bahia","CE"=>"Ceara","DF"=>"Distrito Federal","ES"=>"Espirito Santo","GO"=>"Goias","MA"=>"Maranhao","MT"=>"Mato Grosso","MS"=>"Mato Grosso do Sul","MG"=>"Minas Gerais","PA"=>"Para","PB"=>"Paraiba","PR"=>"Parana","PE"=>"Pernambuco","PI"=>"Piaui","RJ"=>"Rio de Janeiro","RN"=>"Rio Grande do Norte","RO"=>"Rondonia","RS"=>"Rio Grande do Sul","RR"=>"Roraima","SC"=>"Santa Catarina","SE"=>"Sergipe","SP"=>"Sao Paulo","TO"=>"Tocantins"),
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('cep', 'text', array('label' => 'CEP', 'attr' => array('placeholder' => 'CEP da sua casa', 'data-mask' => '00000-000'), 'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 9)))))
        ->getForm();

    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();
            $user = new AdvancedUser($data['email'], $data['senha']);
            $encoder = $app['security.encoder_factory']->getEncoder($user);
            $encodedPassword = $encoder->encodePassword($data['senha'], $user->getSalt());
            $app['db']->insert('clientes', array(
                'telefone' => $data['telefone'], 'celular' => $data['celular'], 'senha' => $encodedPassword,
                'endereco' => $data['endereco'], 'nome' => $data['nome'], 'email' => $data['email'],
                'bairro' => $data['bairro'], 'uf' => $data['estado'], 'cep' => $data['cep'], 'cidade' => $data['cidade']));
            
            $app['session']->getFlashBag()->add('message', 'Cliente registrado com sucesso!');
        }
    }

    return $app['twig']->render('form-bootstrap-anon.html', array('form' => $form->createView()));
});

$app->match('/recuperar-senha', function (Request $request) use ($app) {

    $form = $app['form.factory']->createBuilder('form')
        ->add('email', 'email')
        ->getForm();

    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();
            $sql = "SELECT * FROM clientes WHERE email = ?";
            $cliente = $app['db']->fetchAssoc($sql, array((string)trim($data['email'])));

            if($cliente != null) {
                $random = random_password(8);

                $app['monolog']->addDebug("Nova senha: " . $random);

                $user = new AdvancedUser($data['email'], $random);
                $encoder = $app['security.encoder_factory']->getEncoder($user);
                $encodedPassword = $encoder->encodePassword($random, $user->getSalt());
                $app['db']->update('clientes', array('senha' => $encodedPassword), array('email' => trim($data['email'])));
                
                $app['session']->getFlashBag()->add('message', 'Uma nova senha foi enviada para seu e-mail cadastrado!');
            } else
                $app['session']->getFlashBag()->add('error', 'Não foi encontrado nenhum cliente cadastrado com o e-mail ' . $data['email'] . '!');

        }
    }

    return $app['twig']->render('form-bootstrap-anon.html', array('form' => $form->createView()));
});

$app->get('/cliente', function() use ($app) {
    return $app['twig']->render('cliente.html');
})->bind('cliente');

/* fim clientes */

/* vendas */

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

            $sql = "SELECT DISTINCT(fp.produto) AS id_produto, p.descricao, p.miniatura_foto, p.preco, t.tipo AS tipo_produto ";
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
    $order = array('ip' => $ip, 'items' => array());
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
                        $order['items'][] = array('id_cliente' => 1, 'id_produto' => intval($idProduto), 'id_sabor' => intval($sabores[$i]), 'id_fornada' => intval($idFornada), 'status_pedido' => 1, 'quantidade' => intval($quantidades[$i]));
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

$app->get('/vendas/confirmar-venda/{dataVenda}', function(Request $request, $dataVenda) use ($app) {
    //return $app->redirect('../../produtos');
    $ip = $app['request']->server->get('REMOTE_ADDR');
    $idFornada = intval($request->get('fornada-id'));

    $cliente = $request->get('cliente-nome');
    $clienteEmail = $request->get('cliente-email');
    $localEntrega = $request->get('cliente-local');

    $order_items = $app['session']->get('order_items');

    if($order_items['ip'] == $ip) {
        $cont = 0;
        $corpoMensagem = "";
        foreach($order_items['items'] as $item) {
            $app['db']->insert('pedidos', $item);
            $corpoMensagem .= print_r($item, true);
            $cont++;
        }

        $message = \Swift_Message::newInstance();
        $message->setSubject("Novo pedido de brigadeiros!");
        $message->setFrom(array("contato@brigadeirogourmetdelicia.com.br"));
        $message->setTo(array("contato@brigadeirogourmetdelicia.com.br"));

        $message->setBody("Novo pedido de brigadeiro!\r\n\r\nCliente (Nome/E-mail): " . $cliente . " / " . $email . "\r\nHora/Data:" . date("H:i:s") . " do dia " . date("d/m/Y") . "\r\nFeito a partir do equipamento identificado pelo IP: " . $ip . "\r\n\r\n" . $cont . " itens:\r\n\r\n" . $corpoMensagem;
        $app['monolog']->addDebug("E-mail: " . $email);
        $app['mailer']->send($message);

        $app['session']->getFlashBag()->add('message', 'Pedido registrado com sucesso!');
    } else {
        $app['session']->getFlashBag()->add('error', 'Ocorreu algum erro inesperado!');
    }

    return $app->redirect('/vendas/' . $dataVenda);
});

$app->get('/produtos', function() use ($app) {
    $sql = "SELECT tp.tipo, p.descricao, p.foto FROM produtos p INNER JOIN tipos_produto tp ON p.tipo_produto = tp.id WHERE p.ativo = TRUE";
    $produtos = $app['db']->fetchAll($sql);
    
    return $app['twig']->render('produtos.html', array('produtos' => $produtos));
});

$app->get('/', function() use ($app) {
    return $app['twig']->render('inicial.html');
});

/* funcoes utilitarias */

function random_password( $length = 8 ) {
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+;:,.?";
    $password = substr( str_shuffle( $chars ), 0, $length );
    return $password;
}