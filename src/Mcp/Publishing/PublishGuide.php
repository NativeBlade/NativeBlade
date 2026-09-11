<?php

namespace NativeBlade\Mcp\Publishing;

use NativeBlade\Mcp\Tool;

abstract class PublishGuide implements Tool
{
    abstract protected function platform(): string;

    public function name(): string
    {
        return 'publish_' . $this->platform();
    }

    public function description(): string
    {
        return 'Guide the developer through ' . ($this->platform() === 'android' ? 'Google Play' : 'App Store')
            . ' publication using live AppServiceProvider configuration, effective plugins, official dated requirements and unanswered questions. Return a read-only plan, not an upload. Pass previous answers to continue.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'release_type' => ['type' => 'string', 'enum' => ['unknown', 'first_release', 'update'], 'default' => 'unknown'],
                'answers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Previously confirmed answers keyed by question id. No passwords, signing keys or tokens.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $args): string
    {
        if (array_diff(array_keys($args), ['release_type', 'answers'])) {
            throw new \InvalidArgumentException('Unknown publication argument.');
        }
        $release = Context::choice($args, 'release_type', ['unknown', 'first_release', 'update'], 'unknown');
        $answers = $args['answers'] ?? [];
        if (!is_array($answers) || ($answers !== [] && array_is_list($answers))) {
            throw new \InvalidArgumentException('answers must be an object keyed by question id.');
        }
        $project = Context::project($this->platform());
        $questions = $this->questions($project);
        foreach ($answers as $key => $answer) {
            if (!isset($questions[$key]) || !is_string($answer) || strlen($answer) > 8000) {
                throw new \InvalidArgumentException('Invalid answer id or value. Use returned question ids and text up to 8000 bytes.');
            }
        }
        $remaining = [];
        foreach ($questions as $id => $question) {
            if ($id === 'release_type' && $release !== 'unknown') {
                continue;
            }
            if (trim($answers[$id] ?? '') === '') {
                $remaining[] = ['id' => $id] + $question;
            }
        }
        $config = $project['platforms'][$this->platform()];
        $missing = array_values(array_filter(['identifier', 'version', 'buildNumber'], fn ($key) => !isset($config[$key]) || $config[$key] === ''));
        $guidePath = dirname(__DIR__, 3) . '/docs/docs/guides/publish-' . $this->platform() . '.md';
        $guide = file_get_contents($guidePath);
        if ($guide === false) {
            throw new \RuntimeException('Publication guide is unavailable.');
        }

        return Context::encode([
            'platform' => $this->platform(),
            'release_type' => $release,
            'status' => 'needs_review',
            'communication' => Context::communication(),
            'freshness' => Context::freshness(),
            'project' => $project,
            'missing_configuration' => $missing,
            'answers' => $answers,
            'next_questions' => array_slice($remaining, 0, 3),
            'remaining_questions' => $remaining,
            'guide' => $guide,
            'next_action' => 'Resolve missing configuration and next questions, then apply the guide to the confirmed answers. Read app screens, routes and the intended release diff with available project tools. Use store_listing to draft metadata. A completed interview is not a passed publication check.',
        ]);
    }

    private function questions(array $project): array
    {
        $questions = [
            'release_type' => ['question' => 'É a primeira publicação ou uma atualização?', 'options' => ['Primeira publicação', 'Atualização']],
            'account' => ['question' => $this->platform() === 'android'
                ? 'A conta é pessoal ou de organização? Quando foi criada e já tem acesso à produção?'
                : 'A conta Apple Developer está ativa e você tem acesso ao app no App Store Connect?'],
            'distribution' => ['question' => 'Quem deve receber esta versão?', 'options' => $this->platform() === 'android'
                ? ['Teste interno para validar o build', 'Teste fechado com pessoas convidadas', 'Produção para o público']
                : ['TestFlight interno para a equipe', 'TestFlight externo para pessoas convidadas', 'App Store para o público']],
            'audience' => ['question' => 'Qual é o público, a faixa etária, os países e os idiomas do app? Há conteúdo infantil, de saúde, financeiro ou gerado por usuários?'],
            'access' => ['question' => 'O app exige login ou permite criar conta? Como a revisão acessa as funções e como o usuário exclui a conta? Não envie senhas aqui.'],
            'privacy' => ['question' => 'Quais dados o app, o backend e os SDKs coletam ou compartilham? Informe finalidade, retenção, exclusão e URL pública da política de privacidade.'],
            'monetization' => ['question' => 'Como o app é monetizado?', 'options' => ['Gratuito sem anúncios ou compras', 'Anúncios', 'Compras, assinaturas ou app pago. Explique o que é vendido.']],
            'assets' => ['question' => 'Já existem ícone, capturas reais, categoria e contato de suporte? Informe o que falta.'],
            'build' => ['question' => 'O build de produção foi testado em dispositivo real e está assinado? Informe a versão anterior na loja e o ambiente de build, sem enviar chaves.'],
            'rollout' => ['question' => 'Quando a versão deve ficar disponível após a aprovação?', 'options' => ['Liberar manualmente', 'Liberar automaticamente', 'Definir data ou distribuição gradual quando disponível']],
        ];
        foreach ($project['plugins']['effective'] as $plugin) {
            $prompt = match ($plugin) {
                'analytics' => 'Quais eventos, identificadores e dados de usuário o Analytics envia? Como o consentimento é tratado?',
                'admob' => 'Quais formatos de anúncio são exibidos? Há personalização, rastreamento ou público infantil? Como funciona o consentimento?',
                'payments' => 'O app vende conteúdo digital, serviço físico ou assinatura? Como funcionam restauração e gerenciamento das compras?',
                'geolocation' => 'A localização é aproximada ou precisa? É acessada em segundo plano, armazenada ou enviada a um servidor?',
                'media', 'barcode_scanner' => 'Como câmera, fotos ou vídeos são usados? Os arquivos ficam no dispositivo ou são enviados?',
                'push' => 'Como os tokens de push são armazenados e vinculados a usuários? Há notificações promocionais?',
                default => null,
            };
            if ($prompt !== null) {
                $questions['plugin_' . $plugin] = ['question' => $prompt, 'evidence' => 'Plugin included: ' . $plugin . '. Actual use must be confirmed.'];
            }
        }
        return $questions;
    }
}
