# Módulo de Audiência

- Módulo registrado com chave `audiencia` e perfil `ROLE_AUDIENCIA`.
- Tela principal: `/audiencia/`.
- Chamada fora de ordem: botão **Chamar** por item da fila.
- Publicação no painel: usa `AtendimentoService::chamarSenha`, então continua no mesmo painel já usado pelo Novo SGA.

## Separação Parte/Testemunha

A separação é automática pelo nome do serviço:

- Se o nome do serviço contém `testemunh` (ex: `Testemunha`), entra em **Testemunhas**.
- Caso contrário, entra em **Partes**.

## Fluxo de operação

1. Defina `Local` e `Número da mesa`.
2. Clique em **Chamar** na pessoa desejada (ordem livre).
3. Use **Iniciar**, **Não compareceu** e **Finalizar** para liberar próxima chamada.
