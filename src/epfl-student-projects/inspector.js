import React from "react";
import axios from "axios";
import { InspectorControls } from "@wordpress/block-editor";
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	TextControl,
	FormTokenField,
	Notice,
	Spinner,
} from "@wordpress/components";
import { __, sprintf, _n } from "@wordpress/i18n";

const PROXY_BASE = "wp-content/plugins/wp-gutenberg-epfl/frontend/epfl-student-projects/";

const proxyUrl = (file) =>
	window.location.href.replace(/wp-admin\/.*/, PROXY_BASE + file);

const IDLE = { state: "idle", error: "" };

/**
 * Error raised when the API answered, but with an error message (e.g. "No projects found
 * for the specified managers"), as opposed to a network / server failure.
 */
class ApiAnswerError extends Error {}

/** Builds a readable message from an axios / parsing error. */
const errorMessage = (error) => {
	const data = error && error.response && error.response.data;
	if (data && typeof data.error === "string") {
		return data.error;
	}
	if (error instanceof ApiAnswerError) {
		return error.message;
	}
	if (error && error.response) {
		return sprintf(__("The server answered with status %s.", "epfl"), error.response.status);
	}
	return __("The server could not be reached. Please check your connection and try again.", "epfl");
};

/** Fetches a proxy URL and returns the list it answers, or throws a meaningful error. */
const fetchList = (url) =>
	axios.get(url).then((response) => {
		const data = response.data;
		if (data && !Array.isArray(data) && typeof data.error === "string") {
			throw new ApiAnswerError(data.error);
		}
		if (!Array.isArray(data)) {
			throw new ApiAnswerError(__("Unexpected answer from the server.", "epfl"));
		}
		return data;
	});

const StatusNotice = ({ status, label, onRetry, emptyMessage, isEmpty }) => {
	if (status.state === "loading") {
		return (
			<p>
				<Spinner /> {sprintf(__("Loading %s…", "epfl"), label)}
			</p>
		);
	}
	if (status.state === "error") {
		return (
			<Notice
				status="error"
				isDismissible={false}
				actions={onRetry ? [{ label: __("Retry", "epfl"), onClick: onRetry }] : []}
			>
				{sprintf(__("Unable to load the %1$s: %2$s", "epfl"), label, status.error)}
			</Notice>
		);
	}
	if (status.state === "ok" && isEmpty) {
		return (
			<Notice status="warning" isDismissible={false}>
				{emptyMessage}
			</Notice>
		);
	}
	return null;
};

export default class InspectorControlsStudentProjects extends React.Component {
	constructor(props) {
		super(props);
		this.state = {
			sections: [],
			schools: [],
			tags: [],
			projects: [],
			status: {
				schools: IDLE,
				sections: IDLE,
				tags: IDLE,
				projects: IDLE,
			},
		};
		// Used to ignore answers of outdated requests (e.g. while typing SCIPERs)
		this.requestIds = {};
		this.previewTimer = null;
	}

	componentDidMount() {
		const { apiSource, zenFetchMode } = this.props.attributes;
		if (apiSource === "isa") {
			this.fetchIsaSections();
		} else if (apiSource === "zen") {
			if (zenFetchMode === "section") {
				this.fetchSchools();
				this.fetchZenUnits();
			} else if (zenFetchMode === "school") {
				this.fetchSchools();
			} else if (zenFetchMode === "tag") {
				this.fetchTags();
			}
			this.fetchProjectsPreview();
		}
	}

	componentDidUpdate(prevProps) {
		const keys = ["apiSource", "zenFetchMode", "section", "zenSchool", "professorScipers", "zenTags", "onlyArchivedProjects"];
		const changed = keys.some((k) => prevProps.attributes[k] !== this.props.attributes[k]);
		if (changed && this.props.attributes.apiSource === "zen") {
			// Debounce, so we don't call the API on every keystroke
			clearTimeout(this.previewTimer);
			this.previewTimer = setTimeout(() => this.fetchProjectsPreview(), 500);
		}
	}

	componentWillUnmount() {
		clearTimeout(this.previewTimer);
		this.unmounted = true;
	}

	setStatus(key, status) {
		if (this.unmounted) return;
		this.setState((prev) => ({ status: { ...prev.status, [key]: status } }));
	}

	/**
	 * Runs a request for the given state key, handling loading / error status and stale answers.
	 * `transform` maps the received list to what is stored in the state.
	 */
	load(key, url, transform) {
		const requestId = (this.requestIds[key] || 0) + 1;
		this.requestIds[key] = requestId;
		this.setStatus(key, { state: "loading", error: "" });

		fetchList(url)
			.then((data) => {
				if (this.unmounted || this.requestIds[key] !== requestId) return;
				this.setState({ [key]: transform(data) });
				this.setStatus(key, { state: "ok", error: "" });
			})
			.catch((error) => {
				if (this.unmounted || this.requestIds[key] !== requestId) return;
				if (!(error instanceof ApiAnswerError)) {
					console.error(`Error fetching ${key}:`, error);
				}
				this.setState({ [key]: [] });
				this.setStatus(key, {
					state: error instanceof ApiAnswerError ? "answer" : "error",
					error: errorMessage(error),
				});
			});
	}

	reset(key) {
		this.requestIds[key] = (this.requestIds[key] || 0) + 1;
		this.setState({ [key]: [] });
		this.setStatus(key, IDLE);
	}

	fetchSchools() {
		this.load("schools", proxyUrl("get-schools-zen.php"), (data) =>
			data.map((school) => ({
				label: school.name || school,
				value: school.name || school,
			})),
		);
	}

	fetchTags() {
		this.load("tags", proxyUrl("get-tags-zen.php"), (data) => {
			// Most used tags first so the suggestions list is relevant
			const tags = data
				.filter((tag) => tag && tag.name)
				.sort((a, b) => (b.projectCount || 0) - (a.projectCount || 0))
				.map((tag) => tag.name);
			return [...new Set(tags)];
		});
	}

	fetchZenUnits(school = this.props.attributes.zenSchool) {
		if (!school) {
			this.reset("sections");
			return;
		}
		this.load(
			"sections",
			proxyUrl("get-sections-zen.php") + `?school=${encodeURIComponent(school)}`,
			(data) =>
				data
					.filter((section) => section && section.acronym)
					.map((section) => ({ label: section.acronym, value: section.acronym })),
		);
	}

	fetchIsaSections() {
		this.load("sections", proxyUrl("get-sections-isa.php"), (data) =>
			data
				.filter((section) => section.code && section.code.startsWith("PROJETS_"))
				.map((section) => ({
					label: (section.name && section.name.fr) || section.code,
					value: section.code,
				})),
		);
	}

	/** Fetches the projects matching the current settings, to tell the editor what will be displayed. */
	fetchProjectsPreview() {
		const { apiSource, zenFetchMode, section, zenSchool, professorScipers, zenTags, onlyArchivedProjects } =
			this.props.attributes;
		if (apiSource !== "zen") {
			this.reset("projects");
			return;
		}

		const archived = onlyArchivedProjects ? "1" : "0";
		let query = null;
		if (zenFetchMode === "sciper" && this.getSciperError()) {
			// The SCIPER field already shows what's wrong, don't add a misleading "no projects"
			query = null;
		} else if (zenFetchMode === "sciper" && professorScipers && professorScipers.trim() !== "") {
			query = `sciper=${encodeURIComponent(professorScipers.replace(/\s/g, ""))}&archived=${archived}`;
		} else if (zenFetchMode === "section" && section) {
			query = `unit=${encodeURIComponent(section)}&archived=${archived}`;
		} else if (zenFetchMode === "tag" && zenTags && zenTags.trim() !== "") {
			query = `tags=${encodeURIComponent(zenTags)}`;
		} else if (zenFetchMode === "school" && zenSchool) {
			query = `school=${encodeURIComponent(zenSchool)}`;
		}

		if (query === null) {
			this.reset("projects");
			return;
		}
		this.load("projects", proxyUrl("get-projects-zen.php") + "?" + query, (data) =>
			data.filter((project) => project && typeof project === "object"),
		);
	}

	getSelectedTags() {
		const { zenTags } = this.props.attributes;
		return zenTags
			? zenTags.split(",").map((t) => t.trim()).filter((t) => t !== "")
			: [];
	}

	/** Validates the SCIPER field, returns an error message or null. */
	getSciperError() {
		const value = (this.props.attributes.professorScipers || "").replace(/\s/g, "");
		if (value === "") return null;
		const invalid = value.split(",").filter((s) => s !== "" && !/^\d{6}$/.test(s));
		if (invalid.length > 0) {
			return sprintf(
				__("Invalid SCIPER(s): %s. A SCIPER is a 6-digit number; separate them with commas.", "epfl"),
				invalid.join(", "),
			);
		}
		return null;
	}

	/** Notice telling the editor how many projects will be displayed, or why there is none. */
	renderProjectsPreview() {
		const { attributes } = this.props;
		const status = this.state.status.projects;

		if (status.state === "idle") return null;
		if (status.state === "loading") {
			return (
				<p>
					<Spinner /> {__("Checking the matching projects…", "epfl")}
				</p>
			);
		}
		if (status.state === "error") {
			return (
				<Notice
					status="error"
					isDismissible={false}
					actions={[{ label: __("Retry", "epfl"), onClick: () => this.fetchProjectsPreview() }]}
				>
					{sprintf(__("Unable to load the projects from the Project Portal: %s", "epfl"), status.error)}
				</Notice>
			);
		}
		if (status.state === "answer") {
			// The API answered, but with an error such as "No projects found for the specified managers"
			return (
				<Notice status="warning" isDismissible={false}>
					{sprintf(__("No projects will be displayed. Project Portal says: %s.", "epfl"), status.error.trim().replace(/\.+$/, ""))}
					{attributes.zenFetchMode === "sciper" &&
						" " + __("Please check the SCIPER(s).", "epfl")}
					{attributes.onlyArchivedProjects &&
						" " + __("Note that only archived projects are requested.", "epfl")}
				</Notice>
			);
		}

		let projects = this.state.projects;
		if (projects.length === 0) {
			return (
				<Notice status="warning" isDismissible={false}>
					{__("No projects found with these settings.", "epfl")}
				</Notice>
			);
		}

		const selectedTags = this.getSelectedTags();
		if (attributes.zenFetchMode === "tag" && attributes.zenMatchAllTags && selectedTags.length > 1) {
			const wanted = selectedTags.map((t) => t.toLowerCase());
			projects = projects.filter((p) => {
				const names = (p.tags || []).map((t) => (t && t.name ? t.name.toLowerCase() : ""));
				return wanted.every((w) => names.includes(w));
			});
			if (projects.length === 0) {
				return (
					<Notice status="warning" isDismissible={false}>
						{__("No project has all the selected tags. Disable \"Match all selected tags\" or change the tags.", "epfl")}
					</Notice>
				);
			}
		}

		if (attributes.onlyCurrentProjects) {
			projects = projects.filter((p) => p.status === "ongoing");
			if (projects.length === 0) {
				return (
					<Notice status="warning" isDismissible={false}>
						{__("Projects were found, but none of them is ongoing. Disable \"Only ongoing projects\" to show them.", "epfl")}
					</Notice>
				);
			}
		}

		return (
			<Notice status="success" isDismissible={false}>
				{sprintf(
					_n("%d project will be displayed.", "%d projects will be displayed.", projects.length, "epfl"),
					projects.length,
				)}
			</Notice>
		);
	}

	render() {
		const { attributes, setAttributes } = this.props;
		const { apiSource = "", zenFetchMode = "", zenSchool = "" } = attributes;
		const { status } = this.state;
		const sciperError = this.getSciperError();

		return (
			<InspectorControls>
				<PanelBody title={__("API Source", "epfl")}>
					<SelectControl
						label={__("Select API Source", "epfl")}
						value={apiSource}
						options={[
							{ label: __("Select API", "epfl"), value: "" },
							{ label: "ISA", value: "isa" },
							{ label: "Project Portal", value: "zen" },
						]}
						onChange={(newApiSource) => {
							this.reset("sections");
							this.reset("projects");
							setAttributes({ apiSource: newApiSource, zenFetchMode: "", zenSchool: "", section: "" });
							if (newApiSource === "isa") {
								this.fetchIsaSections();
							}
						}}
					/>
					{apiSource === "" && (
						<Notice status="warning" isDismissible={false}>
							{__("Please select an API source.", "epfl")}
						</Notice>
					)}
				</PanelBody>
				{apiSource === "zen" && (
					<PanelBody title={__("Project Portal Fetch Mode", "epfl")}>
						<SelectControl
							label={__("Fetch By", "epfl")}
							value={zenFetchMode}
							options={[
								{ label: __("Select Mode", "epfl"), value: "" },
								{ label: __("By Unit", "epfl"), value: "section" },
								{ label: __("By Professor SCIPER", "epfl"), value: "sciper" },
								{ label: __("By Tag(s)", "epfl"), value: "tag" },
								{ label: __("By School", "epfl"), value: "school" },
							]}
							onChange={(newMode) => {
								this.reset("sections");
								this.reset("projects");
								const newAttributes = { zenFetchMode: newMode, zenSchool: "", section: "" };
								// The Project Portal tag and school endpoints have no archived variant
								if (newMode === "tag" || newMode === "school") {
									newAttributes.onlyArchivedProjects = false;
								}
								setAttributes(newAttributes);
								if (newMode === "section" || newMode === "school") {
									this.fetchSchools();
								} else if (newMode === "tag") {
									this.fetchTags();
								}
							}}
						/>
						{zenFetchMode === "" && (
							<Notice status="warning" isDismissible={false}>
								{__("Please select how the projects should be fetched.", "epfl")}
							</Notice>
						)}
					</PanelBody>
				)}
				{apiSource === "zen" && (zenFetchMode === "section" || zenFetchMode === "school") && (
					<PanelBody title={__("School", "epfl")}>
						<SelectControl
							label={__("Select School", "epfl")}
							value={zenSchool}
							disabled={status.schools.state === "loading"}
							options={[
								{ label: __("Please choose a school", "epfl"), value: "" },
								...this.state.schools,
							]}
							onChange={(newSchool) => {
								setAttributes({ zenSchool: newSchool, section: "" });
								if (zenFetchMode === "section") {
									this.fetchZenUnits(newSchool);
								}
							}}
						/>
						<StatusNotice
							status={status.schools.state === "answer" ? { ...status.schools, state: "error" } : status.schools}
							label={__("schools", "epfl")}
							onRetry={() => this.fetchSchools()}
							isEmpty={this.state.schools.length === 0}
							emptyMessage={__("The Project Portal did not return any school.", "epfl")}
						/>
						{zenFetchMode === "school" && !zenSchool && status.schools.state === "ok" && this.state.schools.length > 0 && (
							<Notice status="warning" isDismissible={false}>
								{__("Please select a school: all its projects will be displayed.", "epfl")}
							</Notice>
						)}
					</PanelBody>
				)}
				{apiSource === "zen" && zenFetchMode === "section" && zenSchool && (
					<PanelBody title={__("Unit", "epfl")}>
						<SelectControl
							value={attributes.section}
							disabled={status.sections.state === "loading"}
							onChange={(section) => setAttributes({ section })}
							options={[
								{ label: __("Please choose", "epfl"), value: "" },
								...this.state.sections,
							]}
						/>
						<StatusNotice
							status={status.sections.state === "answer" ? { ...status.sections, state: "error" } : status.sections}
							label={__("units", "epfl")}
							onRetry={() => this.fetchZenUnits()}
							isEmpty={this.state.sections.length === 0}
							emptyMessage={sprintf(__("No unit with projects was found for the school %s.", "epfl"), zenSchool)}
						/>
					</PanelBody>
				)}
				{apiSource === "zen" && zenFetchMode === "sciper" && (
					<PanelBody title={__("Professors", "epfl")}>
						<TextControl
							label={__("Professor(s) sciper(s)", "epfl")}
							help={__("Separated with commas", "epfl")}
							value={attributes.professorScipers}
							onChange={(professorScipers) => setAttributes({ professorScipers })}
						/>
						{sciperError && (
							<Notice status="error" isDismissible={false}>
								{sciperError}
							</Notice>
						)}
					</PanelBody>
				)}
				{apiSource === "zen" && zenFetchMode === "tag" && (
					<PanelBody title={__("Tags", "epfl")}>
						<FormTokenField
							label={__("Tag(s)", "epfl")}
							value={this.getSelectedTags()}
							suggestions={this.state.tags}
							maxSuggestions={50}
							disabled={status.tags.state === "loading"}
							__experimentalExpandOnFocus
							__experimentalShowHowTo={false}
							__experimentalValidateInput={(token) =>
								this.state.tags.length === 0 ||
								this.state.tags.some((t) => t.toLowerCase() === token.toLowerCase())
							}
							onChange={(tokens) => {
								const zenTags = tokens
									.map((t) => (typeof t === "string" ? t : t.value).trim())
									.filter((t) => t !== "" && t.indexOf(",") === -1)
									.join(",");
								setAttributes({ zenTags });
							}}
						/>
						<p className="components-base-control__help">
							{__("Type to search and pick one or more tags (topics, levels, sections).", "epfl")}
						</p>
						<StatusNotice
							status={status.tags.state === "answer" ? { ...status.tags, state: "error" } : status.tags}
							label={__("tags", "epfl")}
							onRetry={() => this.fetchTags()}
							isEmpty={this.state.tags.length === 0}
							emptyMessage={__("The Project Portal did not return any tag.", "epfl")}
						/>
						<ToggleControl
							label={__("Match all selected tags", "epfl")}
							help={
								attributes.zenMatchAllTags
									? __("Only projects having every selected tag are shown.", "epfl")
									: __("Projects having at least one of the selected tags are shown.", "epfl")
							}
							checked={!!attributes.zenMatchAllTags}
							onChange={(zenMatchAllTags) => setAttributes({ zenMatchAllTags })}
						/>
					</PanelBody>
				)}
				{apiSource === "isa" && (
					<PanelBody title={__("Section", "epfl")}>
						<SelectControl
							value={attributes.section}
							disabled={status.sections.state === "loading"}
							onChange={(section) => setAttributes({ section })}
							options={[
								{ label: __("Please choose", "epfl"), value: "" },
								...this.state.sections,
							]}
						/>
						<StatusNotice
							status={status.sections.state === "answer" ? { ...status.sections, state: "error" } : status.sections}
							label={__("sections", "epfl")}
							onRetry={() => this.fetchIsaSections()}
							isEmpty={this.state.sections.length === 0}
							emptyMessage={__("IS-Academia did not return any project section.", "epfl")}
						/>
					</PanelBody>
				)}
				{apiSource === "zen" && zenFetchMode !== "" && (
					<PanelBody title={__("Result preview", "epfl")}>
						{this.renderProjectsPreview() || (
							<p className="components-base-control__help">
								{__("Fill in the settings above to see how many projects will be displayed.", "epfl")}
							</p>
						)}
					</PanelBody>
				)}
				<PanelBody title={__("Filters", "epfl")}>
					<ToggleControl
						label={__("Only ongoing projects", "epfl")}
						checked={!!attributes.onlyCurrentProjects}
						onChange={(onlyCurrentProjects) => {
							setAttributes({
								onlyCurrentProjects,
								onlyArchivedProjects: onlyCurrentProjects ? false : attributes.onlyArchivedProjects,
							});
						}}
					/>
					{apiSource === "zen" && zenFetchMode !== "tag" && zenFetchMode !== "school" && (
						<ToggleControl
							label={__("Only archived projects", "epfl")}
							checked={!!attributes.onlyArchivedProjects}
							onChange={(onlyArchivedProjects) => {
								setAttributes({
									onlyArchivedProjects,
									onlyCurrentProjects: onlyArchivedProjects ? false : attributes.onlyCurrentProjects,
								});
							}}
						/>
					)}
					{apiSource === "isa" && (
						<TextControl
							label={__("Professor(s) sciper(s)", "epfl")}
							help={__("Separated with commas", "epfl")}
							value={attributes.professorScipers}
							onChange={(professorScipers) => setAttributes({ professorScipers })}
						/>
					)}
					{apiSource === "isa" && sciperError && (
						<Notice status="error" isDismissible={false}>
							{sciperError}
						</Notice>
					)}
				</PanelBody>
			</InspectorControls>
		);
	}
}
