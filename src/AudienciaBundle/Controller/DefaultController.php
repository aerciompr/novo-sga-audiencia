<?php

namespace App\AudienciaBundle\Controller;

use Exception;
use Novosga\Entity\Atendimento;
use Novosga\Entity\Local;
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
     * @Route("/ajax_update", name="ajaxupdate", methods={"GET"})
     */
    public function ajaxUpdate(FilaService $filaService, UsuarioService $usuarioService)
    {
        $envelope = new Envelope();

        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $localId = $this->getLocalAtendimento($usuarioService, $usuario) ?? 0;
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $tipo = $this->getTipoAtendimento($usuarioService, $usuario);

        $local = $this->getDoctrine()->getRepository(Local::class)->find($localId);

        $servicos = $usuarioService->servicos($usuario, $unidade);
        $atendimentos = $filaService->filaAtendimento($unidade, $usuario, $servicos, $tipo);

        $filas = [
            'parte' => [],
            'testemunha' => [],
        ];

        foreach ($atendimentos as $atendimento) {
            $grupo = $this->grupoAtendimento($atendimento);
            $filas[$grupo][] = $atendimento;
        }

        $envelope->setData([
            'total' => count($atendimentos),
            'filas' => $filas,
            'usuario' => [
                'id' => $usuario->getId(),
                'local' => $local,
                'numeroLocal' => $numeroLocal,
                'tipoAtendimento' => $tipo,
            ],
        ]);

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
     * @Route("/chamar/atendimento/{id}", name="chamar_atendimento", methods={"POST"})
     */
    public function chamarAtendimento(
        Atendimento $atendimento,
        AtendimentoService $atendimentoService,
        FilaService $filaService,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        $envelope = new Envelope();

        /** @var Usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $atual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);
        if ($atual && $atual->getId() !== $atendimento->getId()) {
            throw new Exception($translator->trans('error.attendance.in_process', [], self::DOMAIN));
        }

        $servicos = $usuarioService->servicos($usuario, $unidade);
        $tipo = $this->getTipoAtendimento($usuarioService, $usuario);
        $fila = $filaService->filaAtendimento($unidade, $usuario, $servicos, $tipo);

        $ids = array_map(function (Atendimento $item) {
            return $item->getId();
        }, $fila);

        if (!in_array($atendimento->getId(), $ids, true) && (!$atual || $atual->getId() !== $atendimento->getId())) {
            throw new Exception($translator->trans('error.attendance.invalid', [], self::DOMAIN));
        }

        $localId = $this->getLocalAtendimento($usuarioService, $usuario);
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $local = $this->getDoctrine()->getRepository(Local::class)->find($localId);

        $sucesso = $atendimentoService->chamar($atendimento, $usuario, $local, $numeroLocal);
        if (!$sucesso) {
            throw new Exception($translator->trans('error.attendance.in_process', [], self::DOMAIN));
        }

        $atendimentoService->chamarSenha($unidade, $atendimento);

        $envelope->setData($atendimento->jsonSerialize());

        return $this->json($envelope);
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

    private function grupoAtendimento(Atendimento $atendimento)
    {
        $nomeServico = mb_strtolower((string) $atendimento->getServico()->getNome());

        if (mb_strpos($nomeServico, 'testemunh') !== false) {
            return 'testemunha';
        }

        return 'parte';
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
