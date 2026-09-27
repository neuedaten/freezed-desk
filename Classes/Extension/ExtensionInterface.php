<?php

namespace Neuedaten\FreezedDesk\Extension;

use Neuedaten\FreezedDesk\Agent\AgentSection;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Web\Router;

/**
 * A module or package that extends Desk: the outbox, freezed-desk-social.
 * Commands are registered with the core as usual (extra.freezed.commands);
 * an extension adds what the core registry cannot: its part of the agent
 * guide, UI pages, panels and translations.
 *
 * A package declares its extension in composer.json:
 *
 *     "extra": {"freezed-desk": {"extensions": ["Vendor\\Package\\DeskExtension"]}}
 *
 * A project may add more with desk.extensions (a list of class names).
 * Desk depends on no extension; extensions depend on Desk.
 */
interface ExtensionInterface
{
    /** A short name, e.g. "outbox", "social". */
    public function name(): string;

    /** Whether the extension is active in this project (e.g. configured). */
    public function enabled(DeskContext $context): bool;

    /**
     * Sections for `desk:agent` (A2.3): commands, states, rules of the
     * extension, written by the extension so a project need not copy them.
     *
     * @return AgentSection[]
     */
    public function agentSections(DeskContext $context): array;

    /**
     * Add UI routes. Handlers are [ControllerClass, method] with a
     * controller extending Web\Controllers\Controller; every route names
     * its CLI equivalent (A1.13).
     */
    public function routes(Router $router): void;

    /** A theme folder (templates/, partials/, static/) laid over the desk theme, or null. */
    public function themeRoot(): ?string;

    /**
     * Links in the sidebar.
     *
     * @return array<int, array{label: string, href: string, badge?: int|string|null, level?: string}>
     */
    public function navigation(DeskContext $context): array;

    /**
     * Panels on the overview: a partial name and its variables.
     *
     * @return array<int, array{partial: string, variables: array<string, mixed>}>
     */
    public function dashboardPanels(DeskContext $context): array;

    /**
     * Panels on a record's page, "main" above the fields or "side" in the
     * side column.
     *
     * @return array<int, array{partial: string, variables: array<string, mixed>, position: string}>
     */
    public function recordPanels(DeskContext $context, TypeSchema $schema, Item $item): array;

    /**
     * The extension's part of `desk:status` (A1.9).
     *
     * @return array<string, mixed>
     */
    public function status(DeskContext $context): array;

    /**
     * UI strings of the extension for a locale, key => text.
     *
     * @return array<string, string>
     */
    public function translations(string $locale): array;
}
