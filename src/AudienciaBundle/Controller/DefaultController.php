<?php

namespace App\AudienciaBundle\Controller;

use Exception;
use Novosga\Entity\Atendimento;
use Novosga\Entity\Local;
use Novosga\Entity\Prioridade;
use Novosga\Entity\ServicoUsuario;
use Novosga\Entity\Usuario;
use Novosga\Http\Envelope;
use Novosga\Service\AtendimentoService;
use Novosga\Service\FilaService;
use Novosga\Service\UsuarioService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/", name="audiencia_")
 */
class DefaultController extends AbstractController
{
    private const DOMAIN = 'AudienciaBundle';

    /**
     * @Route("/", name="index", methods={"GET"})
     */
    public function index(
        AtendimentoService $atendimentoService,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario && $usuario->getLotacao() ? $usuario->getLotacao()->getUnidade() : null;

        if (!$usuario || !$unidade) {
            return $this->redirectToRoute('home');
        }

        $em = $this->getDoctrine()->getManager();

        $localId = $this->getLocalAtendimento($usuarioService, $usuario);
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $tipo = $this->getTipoAtendimento($usuarioService, $usuario);
        $local = $localId > 0 ? $em->find(Local::class, $localId) : null;

        $locais = $em->getRepository(Local::class)->findBy([], ['nome' => 'ASC']);

        $tiposAtendimento = [
            FilaService::TIPO_TODOS => $translator->trans('label.all', [], self::DOMAIN),
            FilaService::TIPO_NORMAL => $translator->trans('label.no_priority', [], self::DOMAIN),
            FilaService::TIPO_PRIORIDADE => $translator->trans('label.priority', [], self::DOMAIN),
            FilaService::TIPO_AGENDAMENTO => $translator->trans('label.schedule', [], self::DOMAIN),
        ];

        $atendimentoAtual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        return $this->render('audiencia/index.html.twig', [
            'usuario' => $usuario,
            'unidade' => $unidade,
            'atendimento' => $atendimentoAtual,
            'tiposAtendimento' => $tiposAtendimento,
            'locais' => $locais,
            'local' => $local,
            'numeroLocal' => $numeroLocal,
            'tipoAtendimento' => $tipo,
        ]);
    }

    /**
     * @Route("/audiencias", name="audiencias_list", methods={"GET"})
     */
    public function audienciasList()
    {
        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $conn = $this->getDoctrine()->getConnection();

        $audiencias = $conn->fetchAllAssociative(
            'SELECT id, titulo, sala, status, criado_em FROM audiencia WHERE unidade_id = :unidadeId ORDER BY id DESC',
            ['unidadeId' => $unidade->getId()]
        );

        foreach ($audiencias as &$audiencia) {
            $pessoas = $conn->fetchAllAssociative(
                'SELECT id, audiencia_id, parte_id, tipo, nome, documento, atendimento_id, criado_em
                 FROM audiencia_pessoa
                 WHERE audiencia_id = :audienciaId
                 ORDER BY id ASC',
                ['audienciaId' => (int) $audiencia['id']]
            );

            $partes = [];
            $testemunhasPorParte = [];

            foreach ($pessoas as $pessoa) {
                $pessoa['id'] = (int) $pessoa['id'];
                $pessoa['audiencia_id'] = (int) $pessoa['audiencia_id'];
                $pessoa['parte_id'] = $pessoa['parte_id'] ? (int) $pessoa['parte_id'] : null;
                $pessoa['atendimento_id'] = $pessoa['atendimento_id'] ? (int) $pessoa['atendimento_id'] : null;

                if ($pessoa['tipo'] === 'parte') {
                    $pessoa['testemunhas'] = [];
                    $partes[$pessoa['id']] = $pessoa;
                } else {
                    $parteId = (int) $pessoa['parte_id'];
                    if (!isset($testemunhasPorParte[$parteId])) {
                        $testemunhasPorParte[$parteId] = [];
                    }
                    $testemunhasPorParte[$parteId][] = $pessoa;
                }
            }

            foreach ($partes as $parteId => &$parte) {
                $parte['testemunhas'] = $testemunhasPorParte[$parteId] ?? [];
            }

            $audiencia['id'] = (int) $audiencia['id'];
            $audiencia['partes'] = array_values($partes);
        }

        return $this->json(new Envelope($audiencias));
    }

    /**
     * @Route("/audiencias", name="audiencias_create", methods={"POST"})
     */
    public function audienciasCreate(Request $request)
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $titulo = trim((string) ($data['titulo'] ?? ''));
        $sala = trim((string) ($data['sala'] ?? ''));

        if ($titulo === '') {
            throw new Exception('Informe o título da audiência');
        }
        if ($sala === '') {
            throw new Exception('Informe a sala da audiência');
        }

        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $conn = $this->getDoctrine()->getConnection();
        $conn->insert('audiencia', [
            'unidade_id' => $unidade->getId(),
            'titulo' => $titulo,
            'sala' => $sala,
            'status' => 'ativa',
            'criado_em' => date('Y-m-d H:i:s'),
        ]);

        return $this->json(new Envelope(['id' => (int) $conn->lastInsertId()]));
    }

    /**
     * @Route("/audiencias/{id}/partes", name="audiencias_partes_create", methods={"POST"})
     */
    public function partesCreate(Request $request, int $id)
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $nome = trim((string) ($data['nome'] ?? ''));

        if ($nome === '') {
            throw new Exception('Informe o nome da parte');
        }

        $conn = $this->getDoctrine()->getConnection();
        $this->assertAudienciaExiste($id, $conn);

        $conn->insert('audiencia_pessoa', [
            'audiencia_id' => $id,
            'parte_id' => null,
            'tipo' => 'parte',
            'nome' => $nome,
            'documento' => null,
            'atendimento_id' => null,
            'criado_em' => date('Y-m-d H:i:s'),
        ]);

        return $this->json(new Envelope(['id' => (int) $conn->lastInsertId()]));
    }

    /**
     * @Route("/audiencias/{id}/testemunhas", name="audiencias_testemunhas_create", methods={"POST"})
     */
    public function testemunhasCreate(Request $request, int $id)
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $parteId = (int) ($data['parteId'] ?? 0);
        $nome = trim((string) ($data['nome'] ?? ''));

        if ($nome === '') {
            throw new Exception('Informe o nome da testemunha');
        }
        if ($parteId <= 0) {
            throw new Exception('Informe a parte vinculada à testemunha');
        }

        $conn = $this->getDoctrine()->getConnection();
        $this->assertAudienciaExiste($id, $conn);

        $parte = $conn->fetchAssociative(
            'SELECT id FROM audiencia_pessoa WHERE id = :id AND audiencia_id = :audienciaId AND tipo = :tipo',
            ['id' => $parteId, 'audienciaId' => $id, 'tipo' => 'parte']
        );

        if (!$parte) {
            throw new Exception('Parte inválida para vínculo da testemunha');
        }

        $conn->insert('audiencia_pessoa', [
            'audiencia_id' => $id,
            'parte_id' => $parteId,
            'tipo' => 'testemunha',
            'nome' => $nome,
            'documento' => null,
            'atendimento_id' => null,
            'criado_em' => date('Y-m-d H:i:s'),
        ]);

        return $this->json(new Envelope(['id' => (int) $conn->lastInsertId()]));
    }

    /**
     * @Route("/partes/{id}", name="partes_delete", methods={"DELETE"})
     */
    public function parteDelete(int $id)
    {
        $conn = $this->getDoctrine()->getConnection();
        $parte = $conn->fetchAssociative(
            'SELECT id, audiencia_id FROM audiencia_pessoa WHERE id = :id AND tipo = :tipo',
            ['id' => $id, 'tipo' => 'parte']
        );

        if (!$parte) {
            throw new Exception('Parte não encontrada');
        }

        $conn->beginTransaction();
        try {
            $conn->delete('audiencia_pessoa', [
                'audiencia_id' => $parte['audiencia_id'],
                'parte_id' => $id,
            ]);
            $conn->delete('audiencia_pessoa', [
                'id' => $id,
            ]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return $this->json(new Envelope());
    }

    /**
     * @Route("/testemunhas/{id}", name="testemunhas_delete", methods={"DELETE"})
     */
    public function testemunhaDelete(int $id)
    {
        $conn = $this->getDoctrine()->getConnection();
        $deleted = $conn->delete('audiencia_pessoa', [
            'id' => $id,
            'tipo' => 'testemunha',
        ]);

        if (!$deleted) {
            throw new Exception('Testemunha não encontrada');
        }

        return $this->json(new Envelope());
    }

    /**
     * @Route("/pessoas/{id}/chamar", name="pessoa_chamar", methods={"POST"})
     */
    public function chamarPessoa(
        int $id,
        AtendimentoService $atendimentoService,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        $conn = $this->getDoctrine()->getConnection();

        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $pessoa = $conn->fetchAssociative(
            'SELECT p.*, a.sala FROM audiencia_pessoa p INNER JOIN audiencia a ON a.id = p.audiencia_id WHERE p.id = :id',
            ['id' => $id]
        );
        if (!$pessoa) {
            throw new Exception('Pessoa da audiência não encontrada');
        }

        $atual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        $atendimento = null;
        if (!empty($pessoa['atendimento_id'])) {
            $atendimento = $this->getDoctrine()->getRepository(Atendimento::class)->find((int) $pessoa['atendimento_id']);
        }

        if ($atual && (!$atendimento || $atual->getId() !== $atendimento->getId())) {
            throw new Exception($translator->trans('error.attendance.in_process', [], self::DOMAIN));
        }

        if (!$atendimento) {
            $serviceId = $this->resolveServiceIdForTipo($pessoa['tipo'], $usuarioService, $usuario, $unidade);
            $prioridadeId = $this->resolvePrioridadePadraoId();

            $atendimento = $atendimentoService->distribuiSenha(
                $unidade->getId(),
                $usuario->getId(),
                $serviceId,
                $prioridadeId,
                null
            );

            $conn->update('audiencia_pessoa', [
                'atendimento_id' => $atendimento->getId(),
            ], [
                'id' => $id,
            ]);
        }

        $localId = $this->getLocalAtendimento($usuarioService, $usuario);
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $local = $this->getDoctrine()->getRepository(Local::class)->find($localId);

        if (!$local || !$numeroLocal) {
            throw new Exception($translator->trans('error.place', [], self::DOMAIN));
        }

        $sucesso = $atendimentoService->chamar($atendimento, $usuario, $local, $numeroLocal);
        if (!$sucesso) {
            throw new Exception($translator->trans('error.attendance.in_process', [], self::DOMAIN));
        }

        $atendimentoService->chamarSenha($unidade, $atendimento);
        $this->atualizarMensagemPainel($atendimento, (string) $pessoa['nome'], (string) $pessoa['sala']);

        return $this->json(new Envelope($atendimento->jsonSerialize()));
    }

    /**
     * @Route("/set_local", name="setlocal", methods={"POST"})
     */
    public function setLocal(
        Request $request,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        $envelope = new Envelope();

        try {
            $data = json_decode($request->getContent());
            $localId = (int) ($data->local ?? 0);
            $numero = (int) ($data->numeroLocal ?? 0);
            $tipo = (string) ($data->tipoAtendimento ?? FilaService::TIPO_TODOS);

            if ($numero <= 0) {
                throw new Exception($translator->trans('error.place_number', [], self::DOMAIN));
            }

            if (!in_array($tipo, [
                FilaService::TIPO_TODOS,
                FilaService::TIPO_NORMAL,
                FilaService::TIPO_PRIORIDADE,
                FilaService::TIPO_AGENDAMENTO,
            ], true)) {
                throw new Exception($translator->trans('error.queue_type', [], self::DOMAIN));
            }

            $local = $this->getDoctrine()->getRepository(Local::class)->find($localId);
            if (!$local) {
                throw new Exception($translator->trans('error.place', [], self::DOMAIN));
            }

            /** @var Usuario */
            $usuario = $this->getUser();

            $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_LOCAL, $localId);
            $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_NUM_LOCAL, $numero);
            $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_TIPO, $tipo);

            $envelope->setData([
                'local' => $local,
                'numero' => $numero,
                'tipo' => $tipo,
            ]);
        } catch (Exception $e) {
            $envelope->exception($e);
        }

        return $this->json($envelope);
    }

    /**
     * @Route("/atendimento", name="atendimento", methods={"GET"})
     */
    public function atendimentoAtual(AtendimentoService $atendimentoService)
    {
        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atendimentoAtual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        return $this->json(new Envelope($atendimentoAtual));
    }

    /**
     * @Route("/iniciar", name="iniciar", methods={"POST"})
     */
    public function iniciar(AtendimentoService $atendimentoService, TranslatorInterface $translator)
    {
        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception($translator->trans('error.attendance.empty', [], self::DOMAIN));
        }

        $atendimentoService->iniciarAtendimento($atual, $usuario);

        return $this->json(new Envelope($atual));
    }

    /**
     * @Route("/nao_compareceu", name="naocompareceu", methods={"POST"})
     */
    public function naoCompareceu(AtendimentoService $atendimentoService, TranslatorInterface $translator)
    {
        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception($translator->trans('error.attendance.empty', [], self::DOMAIN));
        }

        $atendimentoService->naoCompareceu($atual, $usuario);

        return $this->json(new Envelope());
    }

    /**
     * @Route("/encerrar", name="encerrar", methods={"POST"})
     */
    public function encerrar(AtendimentoService $atendimentoService, TranslatorInterface $translator)
    {
        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception($translator->trans('error.attendance.not_in_process', [], self::DOMAIN));
        }

        $atendimentoService->encerrar($atual, $unidade, [
            $atual->getServico()->getId(),
        ]);

        return $this->json(new Envelope());
    }

    private function assertAudienciaExiste(int $id, $conn): void
    {
        $exists = $conn->fetchOne('SELECT id FROM audiencia WHERE id = :id', ['id' => $id]);
        if (!$exists) {
            throw new Exception('Audiência não encontrada');
        }
    }

    private function resolveServiceIdForTipo(string $tipo, UsuarioService $usuarioService, Usuario $usuario, $unidade): int
    {
        $servicosUsuario = $usuarioService->servicos($usuario, $unidade);

        $matches = [];
        $fallback = null;

        /** @var ServicoUsuario $servicoUsuario */
        foreach ($servicosUsuario as $servicoUsuario) {
            $servico = $servicoUsuario->getServico();
            $nome = mb_strtolower((string) $servico->getNome());
            if ($fallback === null) {
                $fallback = (int) $servico->getId();
            }

            if ($tipo === 'testemunha' && mb_strpos($nome, 'testemunh') !== false) {
                $matches[] = (int) $servico->getId();
            }

            if ($tipo === 'parte' && mb_strpos($nome, 'parte') !== false) {
                $matches[] = (int) $servico->getId();
            }
        }

        if (count($matches)) {
            return (int) $matches[0];
        }

        if ($fallback !== null) {
            return (int) $fallback;
        }

        throw new Exception('Nenhum serviço disponível para o conciliador');
    }

    private function resolvePrioridadePadraoId(): int
    {
        $repo = $this->getDoctrine()->getRepository(Prioridade::class);
        $prioridade = $repo->findOneBy(['peso' => 0]);

        if (!$prioridade) {
            $prioridade = $repo->findOneBy([], ['id' => 'ASC']);
        }

        if (!$prioridade) {
            throw new Exception('Nenhuma prioridade cadastrada');
        }

        return (int) $prioridade->getId();
    }

    private function atualizarMensagemPainel(Atendimento $atendimento, string $nomePessoa, string $sala): void
    {
        try {
            $senha = $atendimento->getSenha();
            $servico = $atendimento->getServico();
            $unidade = $atendimento->getUnidade();

            $conn = $this->getDoctrine()->getConnection();
            $painelId = $conn->fetchOne(
                'SELECT id FROM painel_senha
                 WHERE unidade_id = :unidade
                   AND servico_id = :servico
                   AND num_senha = :numero
                   AND sig_senha = :sigla
                 ORDER BY id DESC
                 LIMIT 1',
                [
                    'unidade' => $unidade->getId(),
                    'servico' => $servico->getId(),
                    'numero' => $senha->getNumero(),
                    'sigla' => $senha->getSigla(),
                ]
            );

            if ($painelId) {
                $mensagem = trim($nomePessoa . ' - ' . $sala);
                $conn->update(
                    'painel_senha',
                    [
                        'msg_senha' => mb_substr($mensagem, 0, 255),
                        'local' => mb_substr($sala, 0, 20),
                        'nome_cliente' => mb_substr($nomePessoa, 0, 100),
                    ],
                    ['id' => (int) $painelId]
                );
            }
        } catch (\Throwable $e) {
            // evita quebrar o fluxo de chamada caso a customização do painel falhe
        }
    }

    private function getLocalAtendimento(UsuarioService $usuarioService, Usuario $usuario)
    {
        $localMeta = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_LOCAL);

        return $localMeta ? (int) $localMeta->getValue() : null;
    }

    private function getNumeroLocalAtendimento(UsuarioService $usuarioService, Usuario $usuario)
    {
        $meta = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_NUM_LOCAL);

        return $meta ? (int) $meta->getValue() : null;
    }

    private function getTipoAtendimento(UsuarioService $usuarioService, Usuario $usuario)
    {
        $meta = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_TIPO);

        return $meta ? $meta->getValue() : FilaService::TIPO_TODOS;
    }
}
