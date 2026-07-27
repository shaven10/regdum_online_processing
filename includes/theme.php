<?php

require_once __DIR__ . '/compliance.php';

function themePresets(): array
{
    return [
        'green' => [
            'label' => 'Emerald Green',
            'description' => 'Fresh green gradient theme used across the portal and landing page.',
            'swatches' => ['#064e3b', '#059669', '#10b981', '#ecfdf5'],
            'vars' => [
                '--green-900' => '#064e3b',
                '--green-800' => '#065f46',
                '--green-700' => '#047857',
                '--green-600' => '#059669',
                '--green-500' => '#10b981',
                '--green-400' => '#34d399',
                '--green-300' => '#6ee7b7',
                '--green-200' => '#a7f3d0',
                '--green-100' => '#d1fae5',
                '--green-50' => '#ecfdf5',
                '--primary' => '#059669',
                '--primary-dark' => '#047857',
                '--primary-darker' => '#065f46',
                '--primary-light' => 'rgba(5, 150, 105, 0.12)',
                '--primary-soft' => '#ecfdf5',
                '--primary-soft-strong' => '#d1fae5',
                '--accent' => '#10b981',
                '--accent-light' => '#34d399',
                '--accent-violet' => '#14b8a6',
                '--info' => '#0d9488',
                '--body-bg' => '#ecfdf5',
                '--sidebar-bg' => 'linear-gradient(180deg, #064e3b 0%, #022c22 100%)',
            ],
        ],
        'blue' => [
            'label' => 'Classic Blue',
            'description' => 'Professional blue theme for a clean institutional look.',
            'swatches' => ['#1e3a8a', '#2563eb', '#3b82f6', '#eff6ff'],
            'vars' => [
                '--green-900' => '#1e3a8a',
                '--green-800' => '#1e40af',
                '--green-700' => '#1d4ed8',
                '--green-600' => '#2563eb',
                '--green-500' => '#3b82f6',
                '--green-400' => '#60a5fa',
                '--green-300' => '#93c5fd',
                '--green-200' => '#bfdbfe',
                '--green-100' => '#dbeafe',
                '--green-50' => '#eff6ff',
                '--primary' => '#2563eb',
                '--primary-dark' => '#1d4ed8',
                '--primary-darker' => '#1e40af',
                '--primary-light' => 'rgba(37, 99, 235, 0.12)',
                '--primary-soft' => '#eff6ff',
                '--primary-soft-strong' => '#dbeafe',
                '--accent' => '#06b6d4',
                '--accent-light' => '#22d3ee',
                '--accent-violet' => '#8b5cf6',
                '--info' => '#0ea5e9',
                '--body-bg' => '#eef2ff',
                '--sidebar-bg' => 'linear-gradient(180deg, #0b1220 0%, #020617 100%)',
            ],
        ],
        'teal' => [
            'label' => 'Ocean Teal',
            'description' => 'Calm teal tones suited for modern service portals.',
            'swatches' => ['#134e4a', '#0d9488', '#14b8a6', '#f0fdfa'],
            'vars' => [
                '--green-900' => '#134e4a',
                '--green-800' => '#115e59',
                '--green-700' => '#0f766e',
                '--green-600' => '#0d9488',
                '--green-500' => '#14b8a6',
                '--green-400' => '#2dd4bf',
                '--green-300' => '#5eead4',
                '--green-200' => '#99f6e4',
                '--green-100' => '#ccfbf1',
                '--green-50' => '#f0fdfa',
                '--primary' => '#0d9488',
                '--primary-dark' => '#0f766e',
                '--primary-darker' => '#115e59',
                '--primary-light' => 'rgba(13, 148, 136, 0.12)',
                '--primary-soft' => '#f0fdfa',
                '--primary-soft-strong' => '#ccfbf1',
                '--accent' => '#14b8a6',
                '--accent-light' => '#2dd4bf',
                '--accent-violet' => '#06b6d4',
                '--info' => '#0891b2',
                '--body-bg' => '#f0fdfa',
                '--sidebar-bg' => 'linear-gradient(180deg, #134e4a 0%, #042f2e 100%)',
            ],
        ],
        'forest' => [
            'label' => 'Forest Green',
            'description' => 'Deep, rich greens for a formal registrar office feel.',
            'swatches' => ['#14532d', '#166534', '#22c55e', '#f0fdf4'],
            'vars' => [
                '--green-900' => '#14532d',
                '--green-800' => '#166534',
                '--green-700' => '#15803d',
                '--green-600' => '#16a34a',
                '--green-500' => '#22c55e',
                '--green-400' => '#4ade80',
                '--green-300' => '#86efac',
                '--green-200' => '#bbf7d0',
                '--green-100' => '#dcfce7',
                '--green-50' => '#f0fdf4',
                '--primary' => '#16a34a',
                '--primary-dark' => '#15803d',
                '--primary-darker' => '#166534',
                '--primary-light' => 'rgba(22, 163, 74, 0.12)',
                '--primary-soft' => '#f0fdf4',
                '--primary-soft-strong' => '#dcfce7',
                '--accent' => '#22c55e',
                '--accent-light' => '#4ade80',
                '--accent-violet' => '#10b981',
                '--info' => '#059669',
                '--body-bg' => '#f0fdf4',
                '--sidebar-bg' => 'linear-gradient(180deg, #14532d 0%, #052e16 100%)',
            ],
        ],
        'violet' => [
            'label' => 'Royal Violet',
            'description' => 'Violet accents with deep sidebar tones for a distinct brand style.',
            'swatches' => ['#4c1d95', '#7c3aed', '#8b5cf6', '#f5f3ff'],
            'vars' => [
                '--green-900' => '#4c1d95',
                '--green-800' => '#5b21b6',
                '--green-700' => '#6d28d9',
                '--green-600' => '#7c3aed',
                '--green-500' => '#8b5cf6',
                '--green-400' => '#a78bfa',
                '--green-300' => '#c4b5fd',
                '--green-200' => '#ddd6fe',
                '--green-100' => '#ede9fe',
                '--green-50' => '#f5f3ff',
                '--primary' => '#7c3aed',
                '--primary-dark' => '#6d28d9',
                '--primary-darker' => '#5b21b6',
                '--primary-light' => 'rgba(124, 58, 237, 0.12)',
                '--primary-soft' => '#f5f3ff',
                '--primary-soft-strong' => '#ede9fe',
                '--accent' => '#8b5cf6',
                '--accent-light' => '#a78bfa',
                '--accent-violet' => '#06b6d4',
                '--info' => '#6366f1',
                '--body-bg' => '#f5f3ff',
                '--sidebar-bg' => 'linear-gradient(180deg, #4c1d95 0%, #2e1065 100%)',
            ],
        ],
    ];
}

function isValidThemePreset(string $key): bool
{
    return array_key_exists($key, themePresets());
}

function getThemePresetKey(): string
{
    $key = getAppSetting('theme_preset', 'green');
    return isValidThemePreset($key) ? $key : 'green';
}

function getActiveThemePreset(): array
{
    $presets = themePresets();
    $key = getThemePresetKey();
    return $presets[$key];
}

function saveThemePreset(string $key): void
{
    if (!isValidThemePreset($key)) {
        throw new InvalidArgumentException('Invalid theme preset.');
    }
    setAppSetting('theme_preset', $key);
}

function renderThemeStyleTag(): void
{
    $preset = getActiveThemePreset();
    $lines = [];
    foreach ($preset['vars'] as $var => $value) {
        $lines[] = '    ' . $var . ': ' . $value . ';';
    }

    echo '<style id="app-theme-vars">:root {' . "\n";
    echo implode("\n", $lines) . "\n";
    echo '}</style>' . "\n";
}

function renderThemePresetCards(string $selectedKey): void
{
    foreach (themePresets() as $key => $preset) {
        $isActive = $key === $selectedKey;
        $swatches = $preset['swatches'] ?? [];
        ?>
        <label class="theme-preset-card<?= $isActive ? ' is-selected' : '' ?>">
            <input type="radio" name="theme_preset" value="<?= e($key) ?>" <?= $isActive ? 'checked' : '' ?>>
            <span class="theme-preset-preview" aria-hidden="true">
                <?php foreach ($swatches as $swatch): ?>
                    <span style="background: <?= e($swatch) ?>"></span>
                <?php endforeach; ?>
            </span>
            <span class="theme-preset-copy">
                <strong><?= e($preset['label']) ?></strong>
                <span><?= e($preset['description']) ?></span>
            </span>
            <?php if ($isActive): ?>
                <span class="theme-preset-badge">Active</span>
            <?php endif; ?>
        </label>
        <?php
    }
}
