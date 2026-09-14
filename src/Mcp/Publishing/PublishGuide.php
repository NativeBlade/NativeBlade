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

        if ($release === 'unknown') {
            $release = $this->normalizeReleaseType($answers['release_type'] ?? '');
        }

        $remaining = [];
        foreach ($questions as $id => $question) {
            if ($id === 'release_type') {
                if ($release === 'unknown') {
                    $remaining[] = ['id' => $id] + $question;
                }
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

    /**
     * Map a release_type answer to the enum. Accepts the enum value itself or the
     * option label ("First release" / "Update"). Anything unrecognized stays
     * 'unknown' so the question keeps being asked.
     */
    private function normalizeReleaseType(string $answer): string
    {
        $a = mb_strtolower(trim($answer));
        if ($a === 'first_release' || str_contains($a, 'first')) {
            return 'first_release';
        }
        if ($a === 'update' || str_contains($a, 'updat')) {
            return 'update';
        }
        return 'unknown';
    }

    private function questions(array $project): array
    {
        $questions = [
            'release_type' => ['question' => 'Is this the first release or an update?', 'options' => ['First release', 'Update']],
            'account' => ['question' => $this->platform() === 'android'
                ? 'Is the account personal or an organization? When was it created and does it already have production access?'
                : 'Is the Apple Developer account active and do you have access to the app in App Store Connect?'],
            'distribution' => ['question' => 'Who should receive this version?', 'options' => $this->platform() === 'android'
                ? ['Internal testing to validate the build', 'Closed testing with invited people', 'Production for the public']
                : ['Internal TestFlight for the team', 'External TestFlight for invited people', 'App Store for the public']],
            'audience' => ['question' => 'What is the app audience, age range, countries and languages? Is there content for children, health, finance, or user-generated content?'],
            'access' => ['question' => 'Does the app require login or allow creating an account? How does review access the features and how does a user delete their account? Do not send passwords here.'],
            'privacy' => ['question' => 'What data do the app, the backend and the SDKs collect or share? State the purpose, retention, deletion and the public privacy policy URL.'],
            'monetization' => ['question' => 'How is the app monetized?', 'options' => ['Free with no ads or purchases', 'Ads', 'Purchases, subscriptions or a paid app. Explain what is sold.']],
            'assets' => ['question' => 'Do you already have an icon, real screenshots, category and support contact? State what is missing.'],
            'build' => ['question' => 'Was the production build tested on a real device and signed? State the previous store version and the build environment, without sending keys.'],
            'rollout' => ['question' => 'When should the version become available after approval?', 'options' => ['Release manually', 'Release automatically', 'Set a date or a staged rollout when available']],
        ];
        foreach ($project['plugins']['effective'] as $plugin) {
            $prompt = match ($plugin) {
                'analytics' => 'What events, identifiers and user data does Analytics send? How is consent handled?',
                'admob' => 'Which ad formats are shown? Is there personalization, tracking or a child audience? How does consent work?',
                'payments' => 'Does the app sell digital content, a physical service or a subscription? How do purchase restoration and management work?',
                'geolocation' => 'Is the location approximate or precise? Is it accessed in the background, stored, or sent to a server?',
                'media', 'barcode_scanner' => 'How are the camera, photos or videos used? Do the files stay on the device or are they uploaded?',
                'push' => 'How are push tokens stored and linked to users? Are there promotional notifications?',
                default => null,
            };
            if ($prompt !== null) {
                $questions['plugin_' . $plugin] = ['question' => $prompt, 'evidence' => 'Plugin included: ' . $plugin . '. Actual use must be confirmed.'];
            }
        }
        return $questions;
    }
}
