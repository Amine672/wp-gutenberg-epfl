<?php

namespace EPFL\Plugins\Gutenberg\StudentProjects;

use \EPFL\Plugins\Gutenberg\Lib\Utils;

require_once(dirname(__FILE__) . '/../lib/utils.php');

function get_link($url)
{
  if (preg_match('/https?/', $url) === 0)
    $url = 'https://' . $url;
  return '<a href="' . $url . '" target="_blank">' . $url . '</a>';
}

function sortByProjectNameIsa($a, $b)
{
  $titleA = isset($a->project->title) ? $a->project->title : '';
  $titleB = isset($b->project->title) ? $b->project->title : '';
  return strcmp($titleA, $titleB);
}

/**
 * Sanitizes a project description coming from the Project Portal before displaying it.
 * Keeps the same formatting tags as before, but removes anything able to run scripts
 * (event handlers such as onmouseover, "javascript:" links, <script>, ...).
 */
function sanitize_zen_description($html)
{
  $common = array('style' => true, 'class' => true);
  $allowed = array(
    'br'     => array(),
    'p'      => $common,
    'strong' => $common,
    'em'     => $common,
    'ul'     => $common,
    'ol'     => $common,
    'li'     => $common,
    'h3'     => $common,
    'h4'     => $common,
    'a'      => array_merge($common, array('href' => true, 'title' => true, 'target' => true, 'rel' => true)),
  );

  // WordPress' CSS filter rejects any value with parentheses, which would remove the colors written
  // as "color: rgb(…)" by the Project Portal editor. Allow plain rgb()/rgba() values (they can't run code).
  $allow_rgb = function ($allow_css, $css_test_string) {
    if (!$allow_css && preg_match('/^[a-z-]+\s*:\s*rgba?\(\s*[0-9.,\s%]+\)\s*$/i', $css_test_string)) {
      return true;
    }
    return $allow_css;
  };
  add_filter('safecss_filter_attr_allow_css', $allow_rgb, 10, 2);
  // The Project Portal editor often puts non-breaking spaces between all the words, which prevents the
  // browser from wrapping lines (text overflowing the modal, words cut in the middle). Use normal spaces.
  $html = str_replace(array('&nbsp;', '&#160;', '&#xA0;', "\xC2\xA0"), ' ', (string) $html);
  $clean = wp_kses($html, $allowed);
  remove_filter('safecss_filter_attr_allow_css', $allow_rgb, 10);
  return $clean;
}

/**
 * Human readable (and translatable) label for a Project Portal status ("ongoing", "Archived", ...)
 */
function zen_status_label($status)
{
  switch (strtolower(trim((string) $status))) {
    case 'ongoing':
      return __('Ongoing', 'epfl');
    case 'archived':
      return __('Archived', 'epfl');
    default:
      return ucfirst(str_replace('_', ' ', (string) $status));
  }
}

/**
 * Translatable label for a member role coming from the Project Portal ("Supervisor", "Owner", "Student")
 */
function zen_role_label($role)
{
  switch (strtolower(trim((string) $role))) {
    case 'supervisor':
      return __('Supervisor', 'epfl');
    case 'owner':
      return __('Owner', 'epfl');
    case 'student':
      return __('Student', 'epfl');
    default:
      return (string) $role;
  }
}

/**
 * Initials of a person ("Kate Maulaz" -> "KM"), used as avatar in the project details
 */
function zen_initials($first_name, $last_name)
{
  $initials = '';
  foreach (array($first_name, $last_name) as $part) {
    $part = trim((string) $part);
    if ($part !== '') {
      $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
  }
  return $initials;
}

/**
 * Calls the Project Portal API with caching:
 * - an answer (list of projects, or {"error": ...} like "no projects found") is cached for 15 minutes,
 * - the last list successfully received is kept as a fallback, used if the Project Portal is down,
 * - a failure is remembered for 1 minute, so a down Project Portal doesn't slow down every page view.
 * Returns the same as Utils::zen_api_request(): decoded JSON, or false on failure.
 */
function zen_cached_request($url)
{
  $key = 'epfl_sp_' . md5($url);
  $fallback_key = 'epfl_sp_last_' . md5($url);

  $cached = get_transient($key);
  if ($cached !== false) {
    if ($cached === 'failed') {
      $last = get_option($fallback_key);
      return $last !== false ? $last : false;
    }
    return $cached;
  }

  $data = Utils::zen_api_request($url);

  if ($data === false || $data === null) {
    set_transient($key, 'failed', MINUTE_IN_SECONDS);
    $last = get_option($fallback_key);
    if ($last !== false) {
      error_log("EPFL Student Projects: Project Portal unreachable, using last known data for " . $url);
      return $last;
    }
    return false;
  }

  set_transient($key, $data, 15 * MINUTE_IN_SECONDS);
  // Keep the last valid list of projects (not error answers) as a fallback, not autoloaded
  if (is_array($data) && !isset($data['error']) && array_values($data) === $data) {
    update_option($fallback_key, $data, false);
  }
  return $data;
}

function sortByProjectNameZen($a, $b)
{
  $titleA = isset($a['title']) ? $a['title'] : '';
  $titleB = isset($b['title']) ? $b['title'] : '';
  return strcmp($titleA, $titleB);
}

function handle_isa($attributes)
{
  $title = Utils::get_sanitized_attribute($attributes, 'title');
  $section = Utils::get_sanitized_attribute($attributes, 'section');
  $only_current_projects = Utils::get_sanitized_attribute($attributes, 'onlyCurrentProjects', '') != '';
  $professor_scipers = Utils::get_sanitized_attribute($attributes, 'professorScipers');
  $professor_scipers = preg_replace('/\s/', '', $professor_scipers);

  if ($section == '') {
    // Show validation error if section is empty
    return Utils::render_user_msg(__("The form was not properly filled. Please select a section.", 'epfl'));
  }

  $target_host = 'isa.epfl.ch';
  //$target_host = 'ditex-web.epfl.ch';

  $url = "https://" . $target_host . "/services/v1/projects/" . $section;

  $search_params = array();

  if ($only_current_projects)
    $search_params[] = 'date-active=' . date("Y-m-d");
  if ($professor_scipers != "") {
    foreach (explode(',', $professor_scipers) as $sciper) {
      $search_params[] = 'professor=' . $sciper;
    }
  }

  if (count($search_params) > 0)
    $url .= "/search?" . implode('&', $search_params);
  $items = Utils::get_items($url, 0, 5, false);

  if ($items === false || $items === null) {
    return Utils::render_user_msg(__("Unable to load the projects: IS-Academia could not be reached or returned an invalid answer. Please try again later.", 'epfl'));
  }

  if (!is_array($items)) {
    return Utils::render_user_msg(__("Unable to load the projects: unexpected answer from IS-Academia.", 'epfl'));
  }

  // Skip malformed entries and "deleted" projects
  $items = array_values(array_filter($items, function ($item) {
    return is_object($item) && isset($item->project) && !(isset($item->project->status->code) && $item->project->status->code == 'STATUT_STAGE_SUPPR');
  }));

  if (empty($items)) {
    $msg = __("No projects found for this section.", 'epfl');
    if ($professor_scipers != '') {
      $msg .= ' ' . __("Please check the professor SCIPER(s) filter.", 'epfl');
    }
    if ($only_current_projects) {
      $msg .= ' ' . __("Note that only ongoing projects are requested.", 'epfl');
    }
    return Utils::render_user_msg($msg);
  }

  usort($items, 'EPFL\Plugins\Gutenberg\StudentProjects\sortByProjectNameIsa');

  ob_start();
  ?>
  <div id='student-projects-list' class="container">
    <h2><?php echo $title ?></h2>
    <div class="form-group">
      <input type="text" id="student-projects-search-input" class="form-control search mb-2"
        placeholder="<?php _e('Search', 'epfl') ?>" aria-describedby="student-projects-search-input">
      <button class="btn btn-secondary sort asc" data-sort="title"><?php _e('Sort by project name', 'epfl') ?></button>
      <button class="btn btn-secondary sort" data-sort="project-id"><?php _e('Sort by project ID', 'epfl') ?></button>
      <button class="btn btn-secondary sort" data-sort="professor1-name"><?php _e('Sort by professor', 'epfl') ?></button>
      <button class="btn btn-secondary sort" data-sort="project-type"><?php _e('Sort by type', 'epfl') ?></button>
    </div>

    <div class="list">

      <?php foreach ($items as $item):

        // to skip "deleted" projects
        if ($item->project->status->code == 'STATUT_STAGE_SUPPR') {
          continue;
        }

        // To use ad ID for collapsing/expanding project
        $project_id = md5($title . $item->project->noProjet->fr);

        $types = array();
        foreach ($item->project->types as $type) {
          $types[] = $type->fr;
        }


        $professors = array();
        $professors_name_only = array();
        if ($item->project->enseignants->principal1->sciper != '') {
          // To extract beginning of the string if exists: "Laboratoire d'automatique 3, IGM – Gestion" to "Laboratoire d'automatique 3"
          $parts = explode(',', $item->project->enseignants->principal1->laboratory->fr);
          $first_part = reset($parts);
          $first_part ? $first_part = ' <small>(' . $first_part . ')</small>' : '';

          $professors[] = '<a href="https://people.epfl.ch/' . $item->project->enseignants->principal1->sciper . '" target="_blank" class="professor1-name">' . $item->project->enseignants->principal1->name->fr .
            '</a>' . $first_part;
          $professors_name_only[] = $item->project->enseignants->principal1->name->fr;
        }
        if ($item->project->enseignants->principal2->sciper != '') {
          $professors[] = '<a href="https://people.epfl.ch/' . $item->project->enseignants->principal2->sciper . '" target="_blank">' . $item->project->enseignants->principal2->name->fr . '</a>';
          $professors_name_only[] = $item->project->enseignants->principal2->name->fr;
        }

        $details = array();

        if ($item->project->commentaire !== null) {
          $details[] = array('Comment', str_replace("\r\n", '</p><p>', $item->project->commentaire->fr));
        }
        $details[] = array('Professor(s)', implode(", ", $professors));

        if ($item->project->administrateur !== null) {
          // Format is "<firstName> <lastName> (<sciper>)" and we need to explode to have "name" and "sciper"...
          $matches = array();
          preg_match('/(.*?)\s\(([0-9]+)\)/', $item->project->administrateur->fr, $matches);
          $details[] = array('Administration', '<a href="https://people.epfl.ch/' . $matches[2] . '" target="_blank">' . $matches[1] . '</a>');
        }


        $external = array();
        if ($item->project->externe->laboratoire !== null)
          $external[] = $item->project->externe->laboratoire->fr;
        if ($item->project->externe->site !== null) {
          // website is a link
          if (preg_match('/^http/', $item->project->externe->site->fr) === 1) {
            $external[] = '<a href="' . $item->project->externe->site->fr . '" target="_blank">' . $item->project->externe->site->fr . '</a>';
          } else {
            $external[] = $item->project->externe->site->fr;
          }
        }

        if ($item->project->externe->email !== null)
          $external[] = '<a href="mailto:' . $item->project->externe->email->fr . '">' . $item->project->externe->email->fr . '</a>';
        if (count($external) > 0)
          $details[] = array('External', implode(", ", $external));

        if ($item->project->site !== null)
          $details[] = array('Site', '<a href="' . $item->project->site->fr . '" target="_blank">' . $item->project->site->fr . '</a>');

        ?>

        <section class="collapse-container">
          <header class="collapse-title collapse-title-desktop collapsed" data-toggle="collapse"
            data-target="#project-available-<?php echo $project_id; ?>" aria-expanded="false"
            aria-controls="project-available-<?php echo $project_id; ?>">
            <p class="title"><?php echo $item->project->title; ?></p>
            <ul class="project-data list-inline has-sep small text-muted">
              <li class="project-id">ID: <?php echo $item->project->noProjet->fr; ?></li>
              <li class="project-type"><span class="sr-only">Type(s): </span><?php echo implode(", ", $types); ?></li>
              <li><span class="sr-only">Section(s): </span><?= $item->project->section->fr ?? ''; ?></li>
              <li><span class="sr-only">Status: </span><?= $item->project->status->label ?? '' ?></li>
              <li><span class="sr-only">Professor: </span><?php echo implode(", ", $professors_name_only); ?></li>
            </ul>
          </header>

          <div class="collapse collapse-item collapse-item-desktop project-description"
            id="project-available-<?php echo $project_id; ?>">
            <?php if ($item->project->image->link !== null): ?>
              <div class="project-thumb alignright">
                <picture>
                  <img src="<?php echo 'https://' . $target_host . '/' . $item->project->image->link->href; ?>"
                    class="img-fluid" style="width:100%;" alt="ALT">
                </picture>
              </div>
            <?php endif; ?>
            <p><?php echo str_replace("\r\n", '</p><p>', $item->project->descriptif->fr); ?></p>

            <dl class="definition-list definition-list-grid">
              <?php foreach ($details as $detail): ?>
                <dt><?php echo $detail[0]; ?></dt>
                <dd><?php echo $detail[1]; ?></dd>
              <?php endforeach; ?>

            </dl>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
  $content = ob_get_contents();
  ob_end_clean();
  return $content;
}

/**
 * True when the block is rendered for the live preview in the block editor (REST block-renderer endpoint).
 * Other REST requests (e.g. /wp/v2/pages content) are rendered like the published page.
 */
function is_editor_preview()
{
  $rest_route = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
  return defined('REST_REQUEST') && REST_REQUEST && strpos($rest_route, '/wp/v2/block-renderer/') === 0;
}

/**
 * The plugin loads its translations on the "wp" hook, which doesn't run for REST requests:
 * load them for the editor preview, in the editor's language.
 */
function load_translations_for_editor_preview()
{
  if (!is_editor_preview() || !function_exists('determine_locale')) {
    return;
  }
  $mo_file = dirname(__FILE__) . '/../../languages/epfl-' . determine_locale() . '.mo';
  if (file_exists($mo_file)) {
    unload_textdomain('epfl');
    load_textdomain('epfl', $mo_file);
  }
}

function epfl_student_projects_block($attributes, $inner_content)
{
  load_translations_for_editor_preview();

  $api_source = Utils::get_sanitized_attribute($attributes, 'apiSource');

  // Validation: Check if API source is selected
  if (empty($api_source)) {
    return Utils::render_user_msg(__("The form was not properly filled. Please select an API source.", 'epfl'));
  }

  switch ($api_source) {
    case 'zen':
      return handle_zen($attributes);
    case 'isa':
      return handle_isa($attributes);
    default:
      return Utils::render_user_msg(__("The form was not properly filled. Invalid API source selected.", 'epfl'));
  }
}

function handle_zen($attributes)
{
    $section = Utils::get_sanitized_attribute($attributes, 'section');
    $zenSchool = Utils::get_sanitized_attribute($attributes, 'zenSchool');
    $title = Utils::get_sanitized_attribute($attributes, 'title');
    $zenFetchMode = Utils::get_sanitized_attribute($attributes, 'zenFetchMode');
    $professorScipers = Utils::get_sanitized_attribute($attributes, 'professorScipers');
    $zenTags = array_values(array_filter(array_map('trim', explode(',', Utils::get_sanitized_attribute($attributes, 'zenTags'))), 'strlen'));
    $zenMatchAllTags = Utils::get_sanitized_attribute($attributes, 'zenMatchAllTags', '') != '';
    $onlyArchivedProjects = Utils::get_sanitized_attribute($attributes, 'onlyArchivedProjects', '') != '';
    $onlyCurrentProjects = Utils::get_sanitized_attribute($attributes, 'onlyCurrentProjects', '') != '';

    // Validation: Check if form is properly filled
    if (empty($zenFetchMode)) {
        return Utils::render_user_msg(__("The form was not properly filled. Please select a fetch mode (By Unit, By Professor SCIPER, By Tag(s) or By School).", 'epfl'));
    }
    
    if ($zenFetchMode === 'section' && empty($section)) {
        return Utils::render_user_msg(__("The form was not properly filled. Please select a unit.", 'epfl'));
    }
    
    if ($zenFetchMode === 'sciper' && empty($professorScipers)) {
        return Utils::render_user_msg(__("The form was not properly filled. Please enter at least one professor SCIPER.", 'epfl'));
    }

    if ($zenFetchMode === 'sciper') {
        $invalidScipers = array_filter(explode(',', preg_replace('/\s/', '', $professorScipers)), function($s) {
            return $s !== '' && !preg_match('/^\d{6}$/', $s);
        });
        if (!empty($invalidScipers)) {
            return Utils::render_user_msg(sprintf(
                __("The form was not properly filled. Invalid professor SCIPER(s): %s. A SCIPER is a 6-digit number; separate them with commas.", 'epfl'),
                htmlspecialchars(implode(', ', $invalidScipers))
            ));
        }
    }

    if ($zenFetchMode === 'school' && empty($zenSchool)) {
        return Utils::render_user_msg(__("The form was not properly filled. Please select a school.", 'epfl'));
    }

    if ($zenFetchMode === 'tag' && empty($zenTags)) {
        return Utils::render_user_msg(__("The form was not properly filled. Please select at least one tag.", 'epfl'));
    }

    $archivedSuffix = $onlyArchivedProjects ? '/archived' : '';

    if ($zenFetchMode === 'sciper' && !empty($professorScipers)) {
        $sciper = preg_replace('/\s/', '', $professorScipers);
        $url = "https://project-portal.epfl.ch/api/public/projects/manager/" . $sciper . $archivedSuffix;
    } else if ($zenFetchMode === 'section' && !empty($section)) {
        $url = "https://project-portal.epfl.ch/api/public/projects/unit/" . rawurlencode($section) . $archivedSuffix;
    } else if ($zenFetchMode === 'tag' && !empty($zenTags)) {
        // The API returns projects having at least one of the given tags (no archived variant available)
        $url = "https://project-portal.epfl.ch/api/public/projects/tag/" . implode(',', array_map('rawurlencode', $zenTags));
    } else if ($zenFetchMode === 'school' && !empty($zenSchool)) {
        // One school at a time, name is case-sensitive (e.g. "IC"); no archived variant available
        $url = "https://project-portal.epfl.ch/api/public/projects/school/" . rawurlencode($zenSchool);
    } else {
        return Utils::render_user_msg(__("The form was not properly filled. Invalid fetch mode or missing parameters.", 'epfl'));
    }

    $items = zen_cached_request($url);

    // Network error, timeout or invalid JSON
    if ($items === NULL || $items === false) {
        return Utils::render_user_msg(__("Unable to load the projects: the Project Portal could not be reached or returned an invalid answer. Please try again later.", 'epfl'));
    }

    // The API answers with {"error": "..."} when nothing is found (unknown unit, SCIPER without projects,
    // no project for the tags...) or when the request is invalid (e.g. malformed SCIPER)
    if (!is_array($items) || isset($items['error']) || isset($items['message']) || ($items !== array() && array_values($items) !== $items)) {
        $api_error = '';
        if (is_array($items)) {
            $api_error = isset($items['error']) && is_string($items['error']) ? $items['error'] : (isset($items['message']) && is_string($items['message']) ? $items['message'] : '');
        }
        $api_error = rtrim(trim($api_error), '.');
        $msg = $api_error !== ''
            ? sprintf(__("No projects could be loaded from the Project Portal: %s.", 'epfl'), htmlspecialchars($api_error))
            : __("Unable to load the projects: unexpected answer from the Project Portal.", 'epfl');
        if ($zenFetchMode === 'sciper') {
            $msg .= ' ' . __("Please check the professor SCIPER(s).", 'epfl');
        }
        return Utils::render_user_msg($msg);
    }

    // Skip malformed entries and make sure the fields used for rendering exist
    $items = array_values(array_filter($items, 'is_array'));
    foreach ($items as &$item) {
        $item += array('id' => 0, 'title' => '', 'createdAt' => '', 'tags' => array());
    }
    unset($item);

    if (empty($items)) {
        if ($zenFetchMode === 'sciper') {
            $msg = sprintf(__("No projects found for the professor SCIPER(s) %s.", 'epfl'), htmlspecialchars($professorScipers));
        } else if ($zenFetchMode === 'tag') {
            $msg = sprintf(__("No projects found for the tag(s) %s.", 'epfl'), htmlspecialchars(implode(', ', $zenTags)));
        } else if ($zenFetchMode === 'school') {
            $msg = sprintf(__("No projects found for the school %s.", 'epfl'), htmlspecialchars($zenSchool));
        } else {
            $msg = sprintf(__("No projects found for the unit %s.", 'epfl'), htmlspecialchars($section));
        }
        if ($onlyArchivedProjects && in_array($zenFetchMode, array('section', 'sciper'), true)) {
            $msg .= ' ' . __("Note that only archived projects are requested.", 'epfl');
        }
        return Utils::render_user_msg($msg);
    }

    // In "match all" mode, keep only projects having every selected tag
    if ($zenFetchMode === 'tag' && $zenMatchAllTags && count($zenTags) > 1) {
        $wanted = array_map('mb_strtolower', $zenTags);
        $items = array_values(array_filter($items, function($item) use ($wanted) {
            $itemTags = array();
            if (!empty($item['tags'])) {
                foreach ($item['tags'] as $tag) {
                    if (isset($tag['name'])) {
                        $itemTags[] = mb_strtolower($tag['name']);
                    }
                }
            }
            return count(array_diff($wanted, $itemTags)) === 0;
        }));

        if (empty($items)) {
            return Utils::render_user_msg(sprintf(
                __("No project has all the selected tags (%s). Disable \"Match all selected tags\" to show projects having at least one of them.", 'epfl'),
                htmlspecialchars(implode(', ', $zenTags))
            ));
        }
    }

    // Filter ongoing projects if requested
    if ($onlyCurrentProjects) {
        $items = array_filter($items, function($item) {
            return isset($item['status']) && $item['status'] === 'ongoing';
        });
        $items = array_values($items);

        if (empty($items)) {
            return Utils::render_user_msg(__("Projects were found, but none of them is ongoing. Disable \"Only ongoing projects\" to show them.", 'epfl'));
        }
    }

    // Sort initially by project title
    usort($items, 'EPFL\Plugins\Gutenberg\StudentProjects\sortByProjectNameZen');

    // Collect all unique levels for filter buttons
    $allLevels = array();
    foreach ($items as $item) {
        if (!empty($item['tags'])) {
            foreach ($item['tags'] as $tag) {
                if (isset($tag['tagType']['name']) && $tag['tagType']['name'] === 'Level') {
                    $allLevels[$tag['name']] = true;
                }
            }
        }
    }
    $allLevels = array_keys($allLevels);
    sort($allLevels);

    $instance_id = 'epfl-sp-' . substr(md5(uniqid('', true)), 0, 8);

    // Maximum number of projects displayed per page (pagination is handled in js/student-projects.js)
    $page_size = 30;

    // Rendered in the block editor (live preview through the REST API): the front-end script doesn't run
    // there, so only show the first projects to keep the editor light.
    // Editor live preview only (see is_editor_preview())
    $is_editor_preview = is_editor_preview();
    $total_items = count($items);
    $editor_preview_size = 6;
    if ($is_editor_preview && $total_items > $editor_preview_size) {
        $items = array_slice($items, 0, $editor_preview_size);
    }

    // Search / sort / filter / modal behaviour (see js/student-projects.js)
    wp_enqueue_script('epfl-student-projects.js');

    ob_start();
    ?>
    <div class="epfl-student-projects container" id="<?php echo $instance_id; ?>">
        <style>
            #<?php echo $instance_id; ?> .epfl-sp-header{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:.75rem 1.5rem;padding-bottom:1rem;margin-bottom:1.25rem;border-bottom:1px solid #e6e6e6}
            #<?php echo $instance_id; ?> .epfl-sp-title{position:relative;margin:0;padding-bottom:.75rem}
            #<?php echo $instance_id; ?> .epfl-sp-title::after{content:"";position:absolute;left:0;bottom:0;width:3rem;height:4px;background:#ff0000}
            #<?php echo $instance_id; ?> .epfl-sp-total{margin-left:auto;display:inline-flex;align-items:baseline;gap:.35rem;padding:.35rem .9rem;border-radius:50rem;background:#f2f2f2;color:#555;font-size:.875rem;white-space:nowrap}
            #<?php echo $instance_id; ?> .epfl-sp-total strong{color:#212121;font-size:1.0625rem}
            #<?php echo $instance_id; ?> .epfl-sp-toolbar{background:#f7f7f7;border:1px solid #ececec;border-radius:.25rem;padding:1rem 1.25rem;margin-bottom:1.75rem}
            #<?php echo $instance_id; ?> .epfl-sp-label{display:block;margin-bottom:.4rem;font-size:.6875rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#707070}
            #<?php echo $instance_id; ?> .epfl-sp-search-wrap{position:relative}
            #<?php echo $instance_id; ?> .epfl-sp-search-wrap svg{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:#8a8a8a;pointer-events:none}
            #<?php echo $instance_id; ?> .epfl-sp-search{padding-left:2.5rem;background-color:#fff}
            #<?php echo $instance_id; ?> .epfl-sp-keyword-clear{font-size:.8125rem;font-weight:600;padding:.25rem .75rem;line-height:1.4;border-radius:50rem;background-color:#ff0000;border:1px solid #ff0000;color:#fff;box-shadow:none}
            #<?php echo $instance_id; ?> .epfl-sp-keyword-clear:hover,
            #<?php echo $instance_id; ?> .epfl-sp-keyword-clear:focus{background-color:#b51f1f;border-color:#b51f1f;color:#fff}
            #<?php echo $instance_id; ?> .epfl-sp-keyword-filter{margin-top:1rem;padding-top:1rem;border-top:1px solid #e6e6e6}
            #<?php echo $instance_id; ?> .epfl-sp-keyword{cursor:pointer;background:#fff;margin:0 .3rem .3rem 0;font:inherit;font-size:.8125rem;transition:all .15s ease}
            #<?php echo $instance_id; ?> .epfl-sp-keyword:hover,
            #<?php echo $instance_id; ?> .epfl-sp-keyword:focus{border-color:#ff0000;color:#ff0000}
            #<?php echo $instance_id; ?> .epfl-sp-levels{margin-top:1rem;padding-top:1rem;border-top:1px solid #e6e6e6}
            #<?php echo $instance_id; ?> .epfl-sp-sort,
            #<?php echo $instance_id; ?> .epfl-sp-level{font-size:.8125rem;font-weight:600;padding:.25rem .75rem;line-height:1.4;background-color:#fff;border:1px solid #d0d0d0;color:#444;box-shadow:none;transition:all .15s ease}
            #<?php echo $instance_id; ?> .epfl-sp-sort{height:calc(1.5rem + .75rem + 2px);padding:0 .9rem;display:inline-flex;align-items:center}
            #<?php echo $instance_id; ?> .epfl-sp-level{border-radius:50rem;margin:0 .35rem .35rem 0}
            #<?php echo $instance_id; ?> .epfl-sp-sort:hover,
            #<?php echo $instance_id; ?> .epfl-sp-level:hover{border-color:#ff0000;color:#ff0000}
            #<?php echo $instance_id; ?> .epfl-sp-sort.active,
            #<?php echo $instance_id; ?> .epfl-sp-level.active{background-color:#ff0000;border-color:#ff0000;color:#fff}
            #<?php echo $instance_id; ?> .epfl-sp-sort.active:hover,
            #<?php echo $instance_id; ?> .epfl-sp-level.active:hover{color:#fff}
            /* --- project details (modal) --- */
            #<?php echo $instance_id; ?> .epfl-sp-dialog{max-width:980px}
            #<?php echo $instance_id; ?> .epfl-sp-modal .modal-content{border:0;border-top:4px solid #ff0000;border-radius:.25rem;box-shadow:0 1rem 3rem rgba(0,0,0,.25)}
            #<?php echo $instance_id; ?> .epfl-sp-modal .modal-header{align-items:flex-start;padding:1.5rem 1.75rem 1.25rem;border-bottom:1px solid #e6e6e6}
            #<?php echo $instance_id; ?> .epfl-sp-modal-heading{min-width:0}
            #<?php echo $instance_id; ?> .epfl-sp-modal-title{font-size:1.5rem;font-weight:700;line-height:1.3;margin:.6rem 0 .5rem}
            #<?php echo $instance_id; ?> .epfl-sp-modal-meta,
            #<?php echo $instance_id; ?> .epfl-sp-detail-meta{display:flex;flex-wrap:wrap;gap:.35rem 1.25rem;font-size:.875rem;color:#666}
            #<?php echo $instance_id; ?> .epfl-sp-meta-item{display:inline-flex;align-items:center;gap:.4rem}
            #<?php echo $instance_id; ?> .epfl-sp-meta-item svg{color:#999}
            #<?php echo $instance_id; ?> .epfl-sp-modal-x{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;margin-left:1rem;padding:0;border:1px solid #d0d0d0;border-radius:50%;background:#fff;color:#444;cursor:pointer;transition:all .15s ease}
            #<?php echo $instance_id; ?> .epfl-sp-modal-x:hover{border-color:#ff0000;color:#ff0000}
            #<?php echo $instance_id; ?> .epfl-sp-modal-x:focus{outline:none;border-color:#ff0000;color:#ff0000;box-shadow:0 0 0 3px rgba(255,0,0,.25)}
            #<?php echo $instance_id; ?> .epfl-sp-modal .modal-body{padding:1.5rem 1.75rem}
            #<?php echo $instance_id; ?> .epfl-sp-detail{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:2rem}
            #<?php echo $instance_id; ?> .epfl-sp-section-label{margin:0 0 .65rem;font-size:.6875rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#707070}
            #<?php echo $instance_id; ?> .epfl-sp-description{font-size:1rem;line-height:1.65;color:#212121;overflow-wrap:break-word}
            #<?php echo $instance_id; ?> .epfl-sp-description p{margin:0 0 .9rem}
            #<?php echo $instance_id; ?> .epfl-sp-description h3{font-size:1.125rem;margin:1.25rem 0 .5rem}
            #<?php echo $instance_id; ?> .epfl-sp-description h4{font-size:1rem;margin:1rem 0 .4rem}
            #<?php echo $instance_id; ?> .epfl-sp-description ul,
            #<?php echo $instance_id; ?> .epfl-sp-description ol{padding-left:1.25rem;margin-bottom:.9rem}
            #<?php echo $instance_id; ?> .epfl-sp-keywords-block{margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #e6e6e6}
            #<?php echo $instance_id; ?> .epfl-sp-detail-side{align-self:start;padding:1.25rem;background:#f7f7f7;border:1px solid #ececec;border-radius:.25rem}
            #<?php echo $instance_id; ?> .epfl-sp-side-section + .epfl-sp-side-section{margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid #e6e6e6}
            #<?php echo $instance_id; ?> .epfl-sp-people{list-style:none;margin:0;padding:0}
            #<?php echo $instance_id; ?> .epfl-sp-people li{display:flex;align-items:center;gap:.7rem;margin:0 0 .7rem;padding:0}
            #<?php echo $instance_id; ?> .epfl-sp-people li::before{content:none;display:none}
            #<?php echo $instance_id; ?> .epfl-sp-people li:last-child{margin-bottom:0}
            #<?php echo $instance_id; ?> .epfl-sp-avatar{flex:0 0 2.25rem;display:flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:50%;background:#fff;border:1px solid #d0d0d0;font-size:.75rem;font-weight:700;color:#444}
            #<?php echo $instance_id; ?> .epfl-sp-person-name{display:block;font-size:.9375rem;font-weight:600;line-height:1.3}
            #<?php echo $instance_id; ?> .epfl-sp-person-role{display:block;font-size:.75rem;color:#707070}
            #<?php echo $instance_id; ?> .epfl-sp-contact{display:inline-flex;align-items:center;gap:.5rem;font-size:.9375rem;font-weight:600;line-height:1.3;color:#212121;text-decoration:none;background:none;word-break:break-word}
            #<?php echo $instance_id; ?> .epfl-sp-contact span{border-bottom:1px solid #c7c7c7}
            #<?php echo $instance_id; ?> .epfl-sp-contact:hover,
            #<?php echo $instance_id; ?> .epfl-sp-contact:focus{color:#ff0000}
            #<?php echo $instance_id; ?> .epfl-sp-contact:hover span,
            #<?php echo $instance_id; ?> .epfl-sp-contact:focus span{border-bottom-color:#ff0000}
            #<?php echo $instance_id; ?> .epfl-sp-unit{margin:0 0 .5rem;line-height:1.35}
            #<?php echo $instance_id; ?> .epfl-sp-unit:last-child{margin-bottom:0}
            #<?php echo $instance_id; ?> .epfl-sp-unit-acronym{font-weight:700}
            #<?php echo $instance_id; ?> .epfl-sp-unit-name{display:block;font-size:.875rem;color:#555}
            #<?php echo $instance_id; ?> .epfl-sp-website{display:flex;align-items:center;justify-content:center;gap:.4rem}
            #<?php echo $instance_id; ?> .epfl-sp-id{margin:0;font-size:.75rem;color:#8a8a8a}
            #<?php echo $instance_id; ?> .epfl-sp-modal .modal-footer{padding:1rem 1.75rem;background:#fafafa;border-top:1px solid #e6e6e6}
            #<?php echo $instance_id; ?> .epfl-sp-modal-view{display:inline-flex;align-items:center;gap:.45rem}
            @media (max-width: 767.98px){
                #<?php echo $instance_id; ?> .epfl-sp-detail{grid-template-columns:minmax(0,1fr);gap:1.5rem}
                #<?php echo $instance_id; ?> .epfl-sp-modal .modal-header,
                #<?php echo $instance_id; ?> .epfl-sp-modal .modal-body{padding:1.25rem}
                #<?php echo $instance_id; ?> .epfl-sp-modal-title{font-size:1.25rem}
                #<?php echo $instance_id; ?> .epfl-sp-modal .modal-footer{flex-direction:column-reverse;align-items:stretch;padding:.75rem 1.25rem}
                #<?php echo $instance_id; ?> .epfl-sp-modal .modal-footer > *{width:100%;margin:.25rem 0;justify-content:center}
            }
            <?php if ($is_editor_preview): /* the editor content column is narrow: 2 cards per row, stacked toolbar */ ?>
            #<?php echo $instance_id; ?> .epfl-sp-item{flex:0 0 50%;max-width:50%}
            #<?php echo $instance_id; ?> .epfl-sp-toolbar .form-row > div{flex:0 0 100%;max-width:100%}
            #<?php echo $instance_id; ?> .epfl-sp-toolbar .form-row > div:first-child{margin-bottom:1rem !important}
            <?php endif; ?>
        </style>

        <div class="epfl-sp-header">
            <?php if (!empty($title)): ?>
                <h2 class="epfl-sp-title"><?php echo htmlspecialchars($title); ?></h2>
            <?php endif; ?>
            <p class="epfl-sp-total mb-0" aria-live="polite">
                <strong class="epfl-sp-count"><?php echo $total_items; ?></strong>
                <span class="epfl-sp-count-label"
                    data-label-one="<?php esc_attr_e('project', 'epfl'); ?>"
                    data-label-many="<?php esc_attr_e('projects', 'epfl'); ?>"><?php echo $total_items === 1 ? __('project', 'epfl') : __('projects', 'epfl'); ?></span>
            </p>
        </div>

        <?php if ($is_editor_preview): ?>
            <p class="small text-muted font-italic mb-3">
                <?php
                /* translators: 1: number of projects shown in the preview, 2: total number of projects */
                echo esc_html(sprintf(__('Editor preview: %1$d of %2$d projects shown. Search, filters, pagination and project details work on the published page.', 'epfl'), count($items), $total_items));
                ?>
            </p>
        <?php endif; ?>

        <div class="epfl-sp-toolbar">
            <div class="form-row">
                <div class="col-12 col-md-8 col-lg-9 mb-3 mb-md-0">
                    <label for="<?php echo $instance_id; ?>-search" class="epfl-sp-label"><?php _e('Search', 'epfl'); ?></label>
                    <div class="epfl-sp-search-wrap">
                        <svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input type="search" id="<?php echo $instance_id; ?>-search" class="form-control epfl-sp-search" placeholder="<?php esc_attr_e('Search by title, keyword, supervisor…', 'epfl'); ?>">
                    </div>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <span class="epfl-sp-label" id="<?php echo $instance_id; ?>-sort-label"><?php _e('Sort by', 'epfl'); ?></span>
                    <div class="btn-group btn-group-sm epfl-sp-sort-group" role="group" aria-labelledby="<?php echo $instance_id; ?>-sort-label">
                        <button type="button" class="btn epfl-sp-sort active" data-sort="title"><?php _e('Title', 'epfl'); ?></button>
                        <button type="button" class="btn epfl-sp-sort" data-sort="date"><?php _e('Newest', 'epfl'); ?></button>
                    </div>
                </div>
            </div>

            <?php if (!empty($allLevels)): ?>
            <div class="epfl-sp-levels" role="group" aria-label="<?php esc_attr_e('Filter by level (several can be selected)', 'epfl'); ?>">
                <span class="epfl-sp-label"><?php _e('Level', 'epfl'); ?></span>
                <button type="button" class="btn epfl-sp-level active" data-level="all"><?php _e('All', 'epfl'); ?></button>
                <?php foreach ($allLevels as $level): ?>
                    <button type="button" class="btn epfl-sp-level" data-level="<?php echo htmlspecialchars($level); ?>"><?php echo htmlspecialchars($level); ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="epfl-sp-keyword-filter" style="display:none;">
                <span class="epfl-sp-label"><?php _e('Keyword', 'epfl'); ?></span>
                <?php /* translators: %s: keyword */ ?>
                <button type="button" class="btn epfl-sp-keyword-clear" data-label-remove="<?php esc_attr_e('Remove the keyword filter: %s', 'epfl'); ?>">
                    <span class="epfl-sp-keyword-name"></span> <span aria-hidden="true">&times;</span>
                </button>
            </div>
        </div>

        <div class="row epfl-sp-grid">
            <?php foreach ($items as $item):
                $itemLevels = array();
                if (!empty($item['tags'])) {
                    foreach ($item['tags'] as $tag) {
                        if (isset($tag['tagType']['name']) && $tag['tagType']['name'] === 'Level') {
                            $itemLevels[] = $tag['name'];
                        }
                    }
                }
                $date_raw = substr((string) $item['createdAt'], 0, 10);
                $date_fmt = !empty($item['createdAt']) ? date_i18n(/* translators: date format of the project creation date, see https://www.php.net/manual/datetime.format.php */ __('F j, Y', 'epfl'), strtotime($item['createdAt'])) : '';
                $status = isset($item['status']) ? $item['status'] : '';
                $cid = $instance_id . '-p-' . (int) $item['id'];

                $search_parts = array($item['title'], $item['id']);
                $search_parts = array_merge($search_parts, $itemLevels);
                if (!empty($item['tags'])) { foreach ($item['tags'] as $t) { $search_parts[] = $t['name']; } }
                if (!empty($item['creator'])) { $search_parts[] = $item['creator']['firstName'] . ' ' . $item['creator']['lastName']; $search_parts[] = $item['creator']['email']; }
                if (!empty($item['units'])) { foreach ($item['units'] as $u) { $search_parts[] = $u['name_fr']; $search_parts[] = $u['acronym']; } }
                if (!empty($item['projectMembership'])) { foreach ($item['projectMembership'] as $m) { $search_parts[] = $m['user']['firstName'] . ' ' . $m['user']['lastName']; } }
                $search_str = strtolower(implode(' ', array_filter($search_parts)));

                $keyword_tags = array();
                if (!empty($item['tags'])) { foreach ($item['tags'] as $t) { if (!isset($t['tagType']['name']) || $t['tagType']['name'] !== 'Level') { $keyword_tags[] = $t['name']; } } }
            ?>
                <div class="col-12 col-md-6 col-lg-4 mb-4 epfl-sp-item"
                    data-title="<?php echo htmlspecialchars($item['title']); ?>"
                    data-id="<?php echo (int) $item['id']; ?>"
                    data-date="<?php echo htmlspecialchars($date_raw); ?>"
                    data-level="<?php echo htmlspecialchars(implode(',', $itemLevels)); ?>"
                    data-keywords="<?php echo htmlspecialchars(mb_strtolower(implode('|', $keyword_tags))); ?>"
                    data-portal="https://project-portal.epfl.ch/projects/<?php echo (int) $item['id']; ?>"
                    data-search="<?php echo htmlspecialchars($search_str); ?>">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <?php
                                // "Ongoing" is the normal case (the Project Portal returns ongoing projects unless
                                // archived ones are requested), so only show the status when it is something else
                                $show_status = $status !== '' && strtolower($status) !== 'ongoing';
                            ?>
                            <?php if ($show_status || !empty($itemLevels)): ?>
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="epfl-sp-badges">
                                    <?php if ($show_status): ?>
                                        <span class="tag tag-sm tag-secondary"><?php echo htmlspecialchars(zen_status_label($status)); ?></span>
                                    <?php endif; ?>
                                    <?php foreach ($itemLevels as $lvl): ?>
                                        <span class="tag tag-sm tag-primary"><?php echo htmlspecialchars($lvl); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <h3 class="card-title h5"><?php echo htmlspecialchars($item['title']); ?></h3>

                            <?php
                                $excerpt = '';
                                if (!empty($item['description'])) {
                                    $plain = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($item['description']), ENT_QUOTES, 'UTF-8')));
                                    if ($plain !== '') {
                                        $excerpt = mb_substr($plain, 0, 120);
                                        if (mb_strlen($plain) > 120) {
                                            $excerpt = rtrim(mb_substr($excerpt, 0, mb_strrpos($excerpt, ' ') ?: 120)) . '…';
                                        }
                                    }
                                }
                            ?>
                            <?php if ($excerpt !== ''): ?>
                            <p class="small text-muted mb-2 epfl-sp-excerpt"><?php echo htmlspecialchars($excerpt); ?></p>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between align-items-center mt-auto">
                                <button class="btn btn-outline-primary btn-sm epfl-sp-details" type="button"><?php _e('Details', 'epfl'); ?></button>
                                <?php if ($date_fmt): ?>
                                <span class="small text-muted"><?php echo htmlspecialchars($date_fmt); ?></span>
                                <?php endif; ?>
                            </div>
                            <template class="epfl-sp-source">
                                <?php
                                    $units = !empty($item['units']) && is_array($item['units']) ? $item['units'] : array();
                                    $unit_acronyms = array();
                                    foreach ($units as $u) { if (!empty($u['acronym'])) { $unit_acronyms[] = $u['acronym']; } }
                                    $project_url = !empty($item['projectUrl']) ? esc_url($item['projectUrl'], array('http', 'https')) : '';
                                    $creator = !empty($item['creator']) && is_array($item['creator']) ? $item['creator'] : null;
                                    $creator_email = $creator && !empty($creator['email']) ? sanitize_email($creator['email']) : '';
                                    $members = !empty($item['projectMembership']) && is_array($item['projectMembership']) ? $item['projectMembership'] : array();
                                ?>
                                <div class="epfl-sp-detail-meta">
                                    <?php if (!empty($unit_acronyms)): ?>
                                        <span class="epfl-sp-meta-item">
                                            <svg aria-hidden="true" focusable="false" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
                                            <?php echo htmlspecialchars(implode(', ', $unit_acronyms)); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($date_fmt): ?>
                                        <span class="epfl-sp-meta-item">
                                            <svg aria-hidden="true" focusable="false" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                                            <?php /* translators: %s: date */ echo htmlspecialchars(sprintf(__('Created on %s', 'epfl'), $date_fmt)); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="epfl-sp-detail">
                                    <div class="epfl-sp-detail-main">
                                        <h3 class="epfl-sp-section-label"><?php _e('Description', 'epfl'); ?></h3>
                                        <div class="epfl-sp-description">
                                            <?php echo !empty($item['description']) ? sanitize_zen_description($item['description']) : '<p class="text-muted font-italic">' . esc_html__('No description provided', 'epfl') . '</p>'; ?>
                                        </div>

                                        <?php if (!empty($keyword_tags)): ?>
                                        <div class="epfl-sp-keywords-block">
                                            <h3 class="epfl-sp-section-label"><?php _e('Keywords', 'epfl'); ?></h3>
                                            <?php foreach ($keyword_tags as $kt): ?><button type="button" class="tag tag-sm tag-tertiary epfl-sp-keyword" data-keyword="<?php echo htmlspecialchars($kt); ?>" title="<?php esc_attr_e('Show all projects with this keyword', 'epfl'); ?>"><?php echo htmlspecialchars($kt); ?></button><?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <aside class="epfl-sp-detail-side">
                                        <?php if (!empty($members)): ?>
                                        <div class="epfl-sp-side-section">
                                            <h3 class="epfl-sp-section-label"><?php _e('People', 'epfl'); ?></h3>
                                            <ul class="epfl-sp-people">
                                                <?php foreach ($members as $m):
                                                    $first = isset($m['user']['firstName']) ? $m['user']['firstName'] : '';
                                                    $last = isset($m['user']['lastName']) ? $m['user']['lastName'] : '';
                                                    $roles = array();
                                                    if (!empty($m['roles']) && is_array($m['roles'])) { foreach ($m['roles'] as $role) { if (isset($role['name'])) { $roles[] = zen_role_label($role['name']); } } }
                                                ?>
                                                <li>
                                                    <span class="epfl-sp-avatar" aria-hidden="true"><?php echo htmlspecialchars(zen_initials($first, $last)); ?></span>
                                                    <span>
                                                        <span class="epfl-sp-person-name"><?php echo htmlspecialchars(trim($first . ' ' . $last)); ?></span>
                                                        <?php if (!empty($roles)): ?><span class="epfl-sp-person-role"><?php echo htmlspecialchars(implode(', ', $roles)); ?></span><?php endif; ?>
                                                    </span>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($creator && $creator_email !== ''): ?>
                                        <div class="epfl-sp-side-section">
                                            <h3 class="epfl-sp-section-label"><?php _e('Contact', 'epfl'); ?></h3>
                                            <a class="epfl-sp-contact" href="<?php echo esc_url('mailto:' . $creator_email, array('mailto')); ?>">
                                                <svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                                                <span><?php echo htmlspecialchars(trim((isset($creator['firstName']) ? $creator['firstName'] : '') . ' ' . (isset($creator['lastName']) ? $creator['lastName'] : ''))); ?></span>
                                            </a>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (!empty($units)): ?>
                                        <div class="epfl-sp-side-section">
                                            <h3 class="epfl-sp-section-label"><?php echo count($units) > 1 ? esc_html__('Units', 'epfl') : esc_html__('Unit', 'epfl'); ?></h3>
                                            <?php foreach ($units as $unit): ?>
                                                <p class="epfl-sp-unit">
                                                    <span class="epfl-sp-unit-acronym"><?php echo htmlspecialchars(isset($unit['acronym']) ? $unit['acronym'] : ''); ?></span>
                                                    <?php if (!empty($unit['name_fr'])): ?><span class="epfl-sp-unit-name"><?php echo htmlspecialchars($unit['name_fr']); ?></span><?php endif; ?>
                                                </p>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>

                                        <div class="epfl-sp-side-section">
                                            <?php if ($project_url !== ''): ?>
                                            <a class="btn btn-sm btn-secondary btn-block epfl-sp-website mb-3" href="<?php echo $project_url; ?>" target="_blank" rel="noopener noreferrer">
                                                <?php _e('Project website', 'epfl'); ?>
                                                <svg aria-hidden="true" focusable="false" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>
                                            </a>
                                            <?php endif; ?>
                                            <p class="epfl-sp-id"><?php /* translators: %d: project number */ echo esc_html(sprintf(__('Project ID #%d', 'epfl'), (int) $item['id'])); ?></p>
                                        </div>
                                    </aside>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <nav class="epfl-sp-pagination mt-2 mb-4" style="display:none;"
            aria-label="<?php esc_attr_e('Projects pages', 'epfl'); ?>"
            data-page-size="<?php echo (int) $page_size; ?>"
            data-label-previous="<?php esc_attr_e('Previous', 'epfl'); ?>"
            data-label-next="<?php esc_attr_e('Next', 'epfl'); ?>"
            <?php /* translators: %d: page number */ ?>
            data-label-page="<?php esc_attr_e('Page %d', 'epfl'); ?>"
            <?php /* translators: 1: first project shown, 2: last project shown, 3: number of projects */ ?>
            data-label-range="<?php esc_attr_e('Projects %1$s–%2$s of %3$s', 'epfl'); ?>">
            <p class="small text-muted text-center mb-2 epfl-sp-range" aria-live="polite"></p>
            <ul class="pagination justify-content-center flex-wrap mb-0"></ul>
        </nav>

        <div class="alert alert-warning epfl-sp-empty" role="alert" style="display:none;">
            <?php _e('No projects match your search criteria.', 'epfl'); ?>
        </div>

        <div class="modal fade epfl-sp-modal" tabindex="-1" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="<?php echo $instance_id; ?>-modal-title" style="display:none;">
            <div class="modal-dialog modal-dialog-scrollable epfl-sp-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="epfl-sp-modal-heading">
                            <div class="epfl-sp-modal-badges"></div>
                            <h2 class="modal-title epfl-sp-modal-title" id="<?php echo $instance_id; ?>-modal-title"></h2>
                            <div class="epfl-sp-modal-meta"></div>
                        </div>
                        <button type="button" class="epfl-sp-modal-x epfl-sp-modal-close" aria-label="<?php esc_attr_e('Close', 'epfl'); ?>">
                            <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="modal-body epfl-sp-modal-body"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary epfl-sp-modal-close"><?php _e('Close', 'epfl'); ?></button>
                        <a href="https://project-portal.epfl.ch/" class="btn btn-primary epfl-sp-modal-view" target="_blank" rel="noopener noreferrer">
                            <?php _e('View on Project Portal', 'epfl'); ?>
                            <svg aria-hidden="true" focusable="false" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    $content = ob_get_contents();
    ob_end_clean();
    return $content;
}

add_action('init', function () {
    $script = 'js/student-projects.js';
    wp_register_script(
        'epfl-student-projects.js',
        plugins_url($script, __FILE__),
        array(),
        filemtime(dirname(__FILE__) . '/' . $script),
        true
    );
});
