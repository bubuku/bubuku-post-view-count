#!/usr/bin/env bash
# setup-skills.sh — gestiona los symlinks a skills del monorepo Bubuku.
#
# Uso:
#   bash scripts/setup-skills.sh                 # menú interactivo
#   bash scripts/setup-skills.sh --list          # ver skills disponibles + estado
#   bash scripts/setup-skills.sh --add wp-admin  # añadir un skill
#   bash scripts/setup-skills.sh --remove wp-admin
#   bash scripts/setup-skills.sh --all           # enlazar todos
#   bash scripts/setup-skills.sh --sync          # enlazar solo los default:true del catálogo
#   bash scripts/setup-skills.sh --sync-theme    # enlazar los default-theme:true (temas WordPress)
#   bash scripts/setup-skills.sh --update-subs   # git submodule update --remote del monorepo
#   bash scripts/setup-skills.sh --help

set -euo pipefail

# ─── Configuración ────────────────────────────────────────────────────────────

# Directorio del plugin donde se invoca el script. OJO: aquí NO se resuelve el
# symlink a propósito — queremos el plugin que invoca, no el monorepo.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SKILLS_DIR="$PLUGIN_DIR/skills"
AGENTS_FILE="$PLUGIN_DIR/AGENTS.md"

# Ruta por defecto del monorepo, usada solo como último recurso (ver abajo).
DEFAULT_MONOREPO="/Users/bubuku/dev/bubuku-plugins-wp/skills"

# Localiza el monorepo resolviendo el symlink de ESTE script hasta su fichero
# canónico ({monorepo}/scripts/setup-skills.sh) y subiendo dos niveles. Así no
# hay ninguna ruta absoluta hardcodeada: funciona aquí y en cualquier clon.
# Se puede forzar con BUBUKU_SKILLS_REPO. Resolver portable (BSD readlink, sin -f).
#
# Si el script es una COPIA física (proyectos generados con /new-theme o /new-plugin,
# donde rsync/cp materializa el fichero) la resolución apunta al propio proyecto y no
# hay catálogo: en ese caso se cae a DEFAULT_MONOREPO.
_resolve_monorepo() {
    local src="${BASH_SOURCE[0]}"
    case "$src" in /*) ;; *) src="$(pwd)/$src";; esac
    while [ -L "$src" ]; do
        local link; link="$(readlink "$src")"
        case "$link" in /*) src="$link";; *) src="$(dirname "$src")/$link";; esac
    done
    local candidate
    candidate="$( cd "$(dirname "$src")/.." && pwd )"
    if [ -f "$candidate/_meta/catalog.json" ]; then
        printf '%s\n' "$candidate"
    else
        printf '%s\n' "$DEFAULT_MONOREPO"
    fi
}
MONOREPO="${BUBUKU_SKILLS_REPO:-$(_resolve_monorepo)}"
CATALOG="$MONOREPO/_meta/catalog.json"

# ─── Colores ──────────────────────────────────────────────────────────────────
if [ -t 1 ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
    CYAN='\033[0;36m'; BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; CYAN=''; BOLD=''; DIM=''; NC=''
fi

ok()   { printf "${GREEN}✓${NC} %s\n" "$*"; }
fail() { printf "${RED}✗${NC} %s\n" "$*" >&2; exit 1; }
info() { printf "${CYAN}→${NC} %s\n" "$*"; }
warn() { printf "${YELLOW}⚠${NC} %s\n" "$*"; }

# ─── Validación del entorno ───────────────────────────────────────────────────
[ -d "$MONOREPO" ] || fail "Monorepo de skills no encontrado: $MONOREPO
   Exporta BUBUKU_SKILLS_REPO con la ruta correcta si lo has movido."
[ -f "$CATALOG" ]  || fail "Catálogo no encontrado: $CATALOG"
command -v python3 >/dev/null 2>&1 || fail "Se requiere python3 (no encontrado en PATH)."

# ─── Helpers JSON ─────────────────────────────────────────────────────────────
# Devuelve "group_id|skill_name|path|default|default_theme|description" para cada skill.
# 'default'       → se enlaza con --sync       (perfil plugin WordPress)
# 'default_theme' → se enlaza con --sync-theme (perfil tema WordPress)
# La descripción va la última porque puede contener el separador '|'.
catalog_rows() {
    python3 - "$CATALOG" <<'PY'
import json, sys
with open(sys.argv[1]) as f:
    data = json.load(f)
for g in data["groups"]:
    for s in g["skills"]:
        print("|".join([
            g["id"],
            s["name"],
            s["path"],
            str(s.get("default", False)).lower(),
            str(s.get("default-theme", False)).lower(),
            s.get("description", ""),
        ]))
PY
}

catalog_group_label() {
    local group_id="$1"
    python3 - "$CATALOG" "$group_id" <<'PY'
import json, sys
with open(sys.argv[1]) as f:
    data = json.load(f)
for g in data["groups"]:
    if g["id"] == sys.argv[2]:
        print(g["label"])
        break
PY
}

catalog_skill_path() {
    local name="$1"
    catalog_rows | awk -F'|' -v n="$name" '$2 == n { print $3; exit }'
}

# ─── Operaciones sobre el plugin ──────────────────────────────────────────────
is_linked() {
    local name="$1"
    [ -L "$SKILLS_DIR/$name" ]
}

create_link() {
    local name="$1"
    local rel_path
    rel_path="$(catalog_skill_path "$name")" || true
    if [ -z "$rel_path" ]; then
        warn "Skill '$name' no encontrado en el catálogo"
        return 1
    fi

    local target="$MONOREPO/$rel_path"
    [ -d "$target" ] || { warn "Target no existe en disco: $target"; return 1; }

    mkdir -p "$SKILLS_DIR"
    if [ -L "$SKILLS_DIR/$name" ]; then
        rm "$SKILLS_DIR/$name"
    fi
    # Symlink RELATIVO (portable entre máquinas/clones mientras skills/ y el
    # plugin sean hermanos). os.path.relpath vía python3, ya requerido arriba.
    local rel_target
    rel_target="$(python3 -c 'import os,sys; print(os.path.relpath(sys.argv[1], sys.argv[2]))' "$target" "$SKILLS_DIR")"
    ln -s "$rel_target" "$SKILLS_DIR/$name"
    ok "enlazado $name → $rel_path"
}

remove_link() {
    local name="$1"
    if [ -L "$SKILLS_DIR/$name" ]; then
        rm "$SKILLS_DIR/$name"
        ok "desenlazado $name"
    else
        warn "$name no estaba enlazado"
    fi
}

# Crea/recrea los symlinks .claude/skills .codex/skills .gemini/skills → ../skills
# y regenera CLAUDE.md, GEMINI.md, .github/copilot-instructions.md desde AGENTS.md.
regenerate_agent_files() {
    [ -d "$SKILLS_DIR" ] || mkdir -p "$SKILLS_DIR"

    # Symlinks de IDEs a la carpeta skills
    for ide in .claude .codex .gemini; do
        local dir="$PLUGIN_DIR/$ide"
        mkdir -p "$dir"
        local link="$dir/skills"
        if [ -L "$link" ]; then rm "$link"; fi
        if [ -d "$link" ] && [ ! -L "$link" ]; then
            mv "$link" "$dir/skills.backup.$(date +%s)"
        fi
        ln -s "../skills" "$link"
    done
    ok "symlinks .claude/skills, .codex/skills, .gemini/skills → ../skills"

    # Copias / redirección desde AGENTS.md
    if [ -f "$AGENTS_FILE" ]; then
        # CLAUDE.md y GEMINI.md soportan @import
        printf "@AGENTS.md\n" > "$PLUGIN_DIR/CLAUDE.md"
        printf "@AGENTS.md\n" > "$PLUGIN_DIR/GEMINI.md"

        # Copilot no soporta @import → copia literal
        mkdir -p "$PLUGIN_DIR/.github"
        cp "$AGENTS_FILE" "$PLUGIN_DIR/.github/copilot-instructions.md"
        ok "regenerados CLAUDE.md, GEMINI.md y .github/copilot-instructions.md desde AGENTS.md"
    else
        warn "AGENTS.md no existe en el plugin — no se regeneran CLAUDE.md / GEMINI.md / copilot-instructions.md"
    fi
}

# ─── Comandos ─────────────────────────────────────────────────────────────────
cmd_list() {
    printf "${BOLD}Catálogo de skills disponibles${NC}\n"
    printf "${DIM}Monorepo: %s${NC}\n\n" "$MONOREPO"

    local current_group=""
    while IFS='|' read -r group name path default deftheme desc; do
        if [ "$group" != "$current_group" ]; then
            current_group="$group"
            local label
            label="$(catalog_group_label "$group")"
            printf "\n${BOLD}── %s ──${NC}\n" "$label"
        fi
        local marker="[ ]"
        local color="$NC"
        if is_linked "$name"; then
            marker="${GREEN}[x]${NC}"
            color="$BOLD"
        fi
        local def_tag=""
        [ "$default" = "true" ]  && def_tag="$def_tag ${DIM}(default)${NC}"
        [ "$deftheme" = "true" ] && def_tag="$def_tag ${DIM}(tema)${NC}"
        printf "  %b ${color}%-36s${NC}%b ${DIM}%s${NC}\n" "$marker" "$name" "$def_tag" "$desc"
    done < <(catalog_rows)

    printf "\n${DIM}Comandos: --add NAME | --remove NAME | --all | --sync | --sync-theme | --update-subs${NC}\n"
}

cmd_add() {
    create_link "$1" || exit 1
    regenerate_agent_files
}

cmd_remove() {
    remove_link "$1"
    regenerate_agent_files
}

cmd_all() {
    info "Enlazando TODOS los skills del catálogo…"
    while IFS='|' read -r group name path default deftheme desc; do
        is_linked "$name" || create_link "$name"
    done < <(catalog_rows)
    regenerate_agent_files
}

cmd_sync() {
    info "Sincronizando con defaults de plugin (default:true)…"
    while IFS='|' read -r group name path default deftheme desc; do
        if [ "$default" = "true" ]; then
            is_linked "$name" || create_link "$name"
        fi
    done < <(catalog_rows)
    regenerate_agent_files
}

cmd_sync_theme() {
    info "Sincronizando con defaults de tema (default-theme:true)…"
    local found=0
    while IFS='|' read -r group name path default deftheme desc; do
        if [ "$deftheme" = "true" ]; then
            found=1
            is_linked "$name" || create_link "$name"
        fi
    done < <(catalog_rows)
    [ "$found" = "1" ] || warn "Ningún skill marcado con default-theme:true en el catálogo"
    regenerate_agent_files
}

cmd_update_subs() {
    info "Actualizando submodules del monorepo (wp-official, design/taste-skill)…"
    ( cd "$MONOREPO" && git submodule update --remote )
    ok "Submodules actualizados al último commit upstream"
    warn "Recuerda hacer commit en el monorepo: cd $MONOREPO && git add -u && git commit -m 'chore: update submodules'"
}

cmd_interactive() {
    printf "${BOLD}Setup interactivo de skills${NC}\n"
    printf "${DIM}Monorepo: %s${NC}\n\n" "$MONOREPO"

    # Cargar catálogo en arrays paralelos
    local names=() paths=() defaults=() themedefaults=() groups=() descs=()
    while IFS='|' read -r group name path default deftheme desc; do
        groups+=("$group")
        names+=("$name")
        paths+=("$path")
        defaults+=("$default")
        themedefaults+=("$deftheme")
        descs+=("$desc")
    done < <(catalog_rows)

    # Estado inicial: ya enlazados
    local linked=()
    for i in "${!names[@]}"; do
        if is_linked "${names[$i]}"; then
            linked+=(true)
        else
            linked+=(false)
        fi
    done

    while true; do
        printf "${BOLD}Skills (toggle con número, 'a' todos, 'd' defaults de plugin, 't' defaults de tema, 'n' ninguno, Enter para confirmar):${NC}\n"
        local current_group=""
        for i in "${!names[@]}"; do
            if [ "${groups[$i]}" != "$current_group" ]; then
                current_group="${groups[$i]}"
                printf "\n${DIM}── %s ──${NC}\n" "$(catalog_group_label "$current_group")"
            fi
            local marker="[ ]"
            [ "${linked[$i]}" = "true" ] && marker="${GREEN}[x]${NC}"
            local def_tag=""
            [ "${defaults[$i]}" = "true" ]      && def_tag="$def_tag ${DIM}(default)${NC}"
            [ "${themedefaults[$i]}" = "true" ] && def_tag="$def_tag ${DIM}(tema)${NC}"
            printf "  %2d. %b %-36s%b\n" "$((i+1))" "$marker" "${names[$i]}" "$def_tag"
        done

        printf "\nElige (1-%d, a, d, t, n, Enter): " "${#names[@]}"
        read -r choice

        case "$choice" in
            "")
                break
                ;;
            a|A)
                for i in "${!names[@]}"; do linked[$i]=true; done
                ;;
            n|N)
                for i in "${!names[@]}"; do linked[$i]=false; done
                ;;
            d|D)
                for i in "${!names[@]}"; do linked[$i]="${defaults[$i]}"; done
                ;;
            t|T)
                for i in "${!names[@]}"; do linked[$i]="${themedefaults[$i]}"; done
                ;;
            *[!0-9]*|"")
                warn "Entrada inválida: $choice"
                ;;
            *)
                local idx=$((choice-1))
                if [ $idx -ge 0 ] && [ $idx -lt ${#names[@]} ]; then
                    if [ "${linked[$idx]}" = "true" ]; then
                        linked[$idx]=false
                    else
                        linked[$idx]=true
                    fi
                else
                    warn "Número fuera de rango"
                fi
                ;;
        esac
    done

    # Aplicar cambios
    printf "\n${BOLD}Aplicando cambios…${NC}\n"
    for i in "${!names[@]}"; do
        local n="${names[$i]}"
        if [ "${linked[$i]}" = "true" ]; then
            is_linked "$n" || create_link "$n"
        else
            if is_linked "$n"; then remove_link "$n"; fi
        fi
    done
    regenerate_agent_files
    printf "\n${GREEN}${BOLD}✓ Setup completado${NC}\n"
}

show_help() {
    cat <<EOF
${BOLD}setup-skills.sh${NC} — gestiona symlinks a skills del monorepo Bubuku

${BOLD}Uso:${NC}
  bash scripts/setup-skills.sh                 # menú interactivo
  bash scripts/setup-skills.sh --list          # listar catálogo + estado
  bash scripts/setup-skills.sh --add NAME      # añadir un skill
  bash scripts/setup-skills.sh --remove NAME   # quitar un skill
  bash scripts/setup-skills.sh --all           # enlazar todos
  bash scripts/setup-skills.sh --sync          # perfil PLUGIN: enlaza los default:true
  bash scripts/setup-skills.sh --sync-theme    # perfil TEMA:   enlaza los default-theme:true
  bash scripts/setup-skills.sh --update-subs   # actualizar submodules (alias: --update-wp)
  bash scripts/setup-skills.sh --help          # esta ayuda

${BOLD}Perfiles:${NC}
  Cada skill del catálogo puede marcarse con "default" (plugins) y/o "default-theme"
  (temas). Un plugin nuevo arranca con --sync; un tema nuevo, con --sync-theme.
  Ambos son acumulativos: nunca desenlazan nada, solo añaden lo que falte.

${BOLD}Variables de entorno:${NC}
  BUBUKU_SKILLS_REPO   Override del path al monorepo
                       (por defecto: $DEFAULT_MONOREPO)
EOF
}

# ─── Dispatch ─────────────────────────────────────────────────────────────────
if [ $# -eq 0 ]; then
    cmd_interactive
    exit 0
fi

case "${1:-}" in
    --list|-l)
        cmd_list
        ;;
    --add)
        [ $# -ge 2 ] || fail "Falta nombre del skill: --add NAME"
        cmd_add "$2"
        ;;
    --remove|--rm)
        [ $# -ge 2 ] || fail "Falta nombre del skill: --remove NAME"
        cmd_remove "$2"
        ;;
    --all)
        cmd_all
        ;;
    --sync)
        cmd_sync
        ;;
    --sync-theme)
        cmd_sync_theme
        ;;
    --update-subs|--update-wp)
        cmd_update_subs
        ;;
    --help|-h)
        show_help
        ;;
    *)
        fail "Opción desconocida: $1 (usa --help)"
        ;;
esac
