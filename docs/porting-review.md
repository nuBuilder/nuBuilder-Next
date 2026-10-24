# nuBuilder functions – porting review

Compared with nuBuilder 4.9 (GitHub master, July 2026). nuBuilder Next 0.8.107 adds **112 PHP functions**
(`core/form/nuphp_compat.php`) and **130 JavaScript functions** (`assets/js/nu/nucompat.js`) that form code,
events and procedures use. This file lists what needs your review and what was not ported, and why.

## Compatibility report (4.9) – what changed

The compatibility analysis of a real nuBuilder 4.9 application (findings C1–C6, M1–M9, N1–N10) is worked off in steps;
the Compatibility check (Admin tools → *Compatibility check*) lists each finding found in the application's code with the version
that supports it.

| Version | Findings | What |
|---|---|---|
| 0.8.382 | C1–C6, M2, M3 | Browse lists built the nuBuilder way: the form's browse SQL stays as it is (qualified columns, aliases, ORDER BY by alias or position, tables without primary key, duplicate column names, hash variables in column expressions); `nu_filter_match`; `nuAddBrowseTitleSelect`; `multiSelect` title filters. Access 9 (`sob_all_access_condition`) is evaluated when the form is shown and when it is saved, and offered in the form builder. |
| 0.8.383 | M1, M7, M8, N1–N4 | Scripts in HTML objects run after the runtime (before the form's JavaScript); `nuAfterSave` / `nuAfterSaveGlobal` on the reloaded form, `nuCloseAfterSaveGlobal`; `nuFORM` methods (`getProperty`, `setProperty`, `subform`, `data`, `calc`, `scrollList`, `breadcrumbs` …; no table schema in the browser); `nuSERVERRESPONSE`; hooks `nuFormatValueCleared(Global)` (an unreadable date is cleared, as in nuBuilder), `nuSubformFilterOnAddValue(s)`, `nuSubformOnFilterRows`, flags `nuBrowseNoData` / `nuBrowseNoSearchResults`; `nuAddActionCheckbox`, `nuGetStartingTab`, `nuSubformFilterArray`, `nuSubformFilterAddObject`, `nuDatalistShowAllOnArrowClick`, `nuSearchablePopupUtils` (property and list helpers); values set while loading no longer mark the form as edited. `nuCancel` and `nuSortElement` are local variables in nuBuilder, not hooks. |
| 0.8.443 | M6 | `$nuConfigIncludeJS` / `$nuConfigIncludeCSS` on form pages (nuBuilder Next's folder, then nuBuilder's; other hosts allowed by the CSP); `$nuConfigIncludePHP` also from nuBuilder's folder |
| 0.8.459 | M5 | File objects with target *File System* (`sob_input_file_target` 1) upload with Uppy, a library pack (not in `nunext.zip`): the object's HTML code with nuBuilder's replacements (`#uppy_div#`, `#this_object_id#`, `nuInitUppy_<id>`, `.cssNumber`, `Uppy.Core`), `nuUppyCreate` / `nuUppySetLanguage` / `nuUppyGetLanguageCodeAndLocale`, the endpoint `core/nuapi.php` sent to `?p=upload` (login cookie + CSRF instead of `session_id`), the procedure's `$result` and HTTP status as the answer, `nuSetUploadError`. Executable file names (.php, .phtml, .htaccess …) are refused before the procedure runs; fresh installations get a hardened `nu_upload_file_template` |
| 0.8.444 | – | Setup → Include (`set_include`) used on form pages – its `<link>` / `<script>` / `<style>` elements (nuBuilder 4.9 itself saves the field but did not output it in our tests); page *Setup code* edits Header / Style / Include |
| 0.8.427 | – | Browse search with nuBuilder's syntax: `"phrases"` and `-word` / `-"phrase"` exclusions (before: words only, a minus was searched literally; an empty column counts as not containing an excluded word – nuBuilder drops such rows); search words highlighted after loading (`nuHighlightSearch` as in nuBuilder, only in searched columns, all occurrences, accent-insensitive). Difference: marks are `<mark class="nuHighlight">` – nuBuilder's `<span class="nuBrowseSearch">` would clash with the search form's class here; own CSS for highlighting uses `mark.nuHighlight` or the variable `--nu-highlight`. |

## 1. Please review – implemented, but behaviour differs or the purpose is unclear

| Function | nuBuilder | nuBuilder Next | Question |
|---|---|---|---|
| `nuRunPHP($code)` (PHP) | Not hidden: stores the hash and returns an id so the browser opens the procedure output in a new window | Runs the procedure hidden, like `nuRunPHPHidden` | Is the "new window" variant used from PHP anywhere? |
| `nuRunProcedure($code)` (PHP) | `eval()` in the calling function – the procedure sees and changes the caller's local variables | Runs in its own scope; only `$error` is shared | Does any code rely on shared local variables? |
| `nuSetJSONData` / `nuGetJSONData` | Stored in `zzzzsys_session.sss_access` (nuBuilder session) | Stored in the PHP session of nuBuilder Next | OK, or should it use a table so other requests/tools can read it? |
| `nuSetJSONDataAll` | Sets the value for **every** open session | Only for the current session | Needed for all sessions (e.g. broadcast flags)? |
| `nuChangeHashVariable` | Changes `$_POST['nuHash']` for the rest of the request | Changes the hash for the code that runs after it in the same request; system variables are protected | Enough? |
| `nuReturnNewRecord` | Sets RECORD_ID to -1 so the form opens a new record after save | **Ported 0.8.443** | In After Save: the form opens that record (default -1 = new) instead of the saved one |
| `nuSetSaveButtonPosition` | Moves the Save button onto the form at given coordinates | **Ported 0.8.443** | Absolute layout: onto the first tab; other layouts keep it in the action bar |
| jQuery `.css('top' / 'left')` on fields | Each field and label is positioned on its own in nuBuilder | **Mapped 0.8.443** | Absolute layout: top / left of a field (top of its label) go to the wrapper `#nuw_<id>`, so label and field move together |
| `nuLog(...)` | Appends to `temp/nuDebug.txt` | Writes to the PHP error log | Prefer a file in nuBuilder Next or the Debug results (zzzzsys_debug)? |
| `nuGetIPAddress` | Trusts `CF-Connecting-IP` / `X-Forwarded-For` / `Client-IP` always | Proxy headers only with `$nuConfigTrustProxy` (spoofing-safe) | OK? |
| `nuGetLastError` | Last PDO error (queries fail softly) | Always `null` – a failing query stops the code with an error | OK? |
| `nuColorToHex` | ~140 named CSS colours | 24 common names, otherwise the hex value | Extend if your code uses rare colour names. |
| `db_field_exists` | Returns false for the **first** column (`array_search(...) != false` bug) | Correct for every column | Fixed on purpose – fine? |
| `nuStringEndsWith` | Can fail when the needle occurs more than once | Correct | Fixed on purpose. |
| `nuRandomPassword` (JS) | Shuffles the characters (no character twice, length ≤ set size) | Secure random, characters may repeat, any length | OK? |
| `nuDatabaseName()` (JS) | Sent to every user | Only for the globeadmin (empty for users) | OK? |
| `nuSetAccess(id, r)` (JS) | Also sets `nuAccessHiddenUser` styling for the globeadmin | Hides / disables as access 2–4 says, no extra styling | OK? |
| `nuSubformSetHeight` (JS) | Sets the subform height (second call restores) | Sets the maximum height of the scroll area (second call restores) | OK? |
| `nuSubformHideHeader` (JS) | Moves the rows up over the header | Hides the table header of a grid subform | OK? |
| `nuSortSubform(sf, col)` (JS) | Bound to clicks on the column titles | Sorts when called; not bound to the titles automatically | Should title clicks sort grid subforms? |
| `<table>_nulog` (record log) | Written by nuUpdateDatabase on every save | Written the same way since 0.8.349 (also CSV import); shown in the ⋮ menu | – |
| JS hooks `nuOnLookupPopulated` / `…Global` | Called only for lookups in sub-form rows (the function returns early otherwise) | Called for every lookup (0.8.345) | OK? |
| JS hooks `nuOnSetCalendarOptions`, `nuOnSetSelect2Options`, `nuOnOptionsListLoadedGlobal` | Options of vanilla Datepicker / Select2; nuBuilder's options list | Browser date picker (only minDate / maxDate), Tom Select (shared options, `tomselect: {}`), Bootstrap ⋮ menu (0.8.345) | Enough? |
| `nuSubformPaste` (JS) | Writes into existing rows only | Adds rows when the pasted block is longer; read-only cells are skipped | OK? |
| `nuSetHideTabIfOnlyOne(who)` (JS) | Reads the setting `nuHideTabTitleIfOnlyOne` (Setup) | Optional argument `everyone` / `user` / `globeadmin` (default everyone) | Setup settings are not available – OK? |
| `nuPrompt(text, caption, value, format, fn)` (JS) | `format` is accepted but unused in nuBuilder too | Bootstrap dialog; callback `fn(value, ok)` or `nuOnPromptClose(value, ok)` | – |
| `nuAddInputIcon` (JS) | Font Awesome / HTML icon inside the input | Icon before / after the input (`fa…`, `bi-…` or HTML) | Position OK? |
| `nuGetPHP(formId, recordId)` (JS) | Opens the procedure in nuBuilder's editor (breadcrumb) | Opens Procedures → that procedure in a new tab | OK? |
| `nuIsPopup()` (JS) | True inside a nuBuilder drag dialog | True inside nuBuilder Next's popup dialog | – |
| `nuRefreshIframe(id)` (JS) | Calls `nuGetBreadcrumb()` in the iframe | Reloads the iframe | – |
| `nuSaveAction(true)` (JS) | Save & close | Save, then back to the previous breadcrumb; `nuAddActionButtonSaveClose()` uses it | – |
| Auto numbers (`nuAutoNumber`, `nuUpdateCounter`) | New records get the next `sob_input_count` | **New:** also applied when nuBuilder Next saves a new record or subform row (main form and subforms) | Behaviour change – please confirm. |
| `nuSelectOptions($sql)` (PHP) | Also supports `languages` and `SHOW TABLES` | SELECT/WITH/SHOW, JSON array and pipe lists (`v1\|t1\|v2\|t2`) | – |
| `nuDemo($showError)` (PHP) | True in nuBuilder's demo mode (globeadmin demo login) | True for the demo users of `$nuConfigDemoUser` (0.8.140); the error is "Not available in the demo." | – |
| `nuGetRecordURL()` (PHP) | `index.php?f=…&r=…` (nuBuilder) | `index.php?p=form&f=…&r=…` (nuBuilder Next) | Should it point to nuBuilder instead (`$nuConfigNuBuilderURL`)? |

## 2. Not ported – and why

These are almost all internals of nuBuilder's own client (rendering, designers, Ajax, breadcrumbs) or server
(save/load, login, reports, update). Form code normally does not call them. Unsupported **public** functions that
still exist as placeholders write a console warning instead of failing.

### JavaScript (468)

**Placeholders (exist, do nothing, console warning)** (13) – Kept as harmless placeholders so existing code does not break; the feature behind them is missing (column resizing …). `nuSetSelect2` works since 0.8.370 (Tom Select, also `$(…).select2(…)` of form code).

`nuAccessFormSetButtonIcons`, `nuBrowseStyleStatusColumn`, `nuCreateDialog`, `nuDragElement`, `nuHasHiddenModalDragDialog`, `nuOpenWiki`, `nuPrintExcludeColumns`, `nuResizeBrowseColumns`, `nuRunReport`, `nuRunReportSave`, `nuSetBrowseColumnWidth`, `nuSetSnippetFormFilter`, `nuShowVersionInfo`

**Form designer and report designer (drag & drop)** (106) – nuBuilder Next has its own form builder; there is no report designer.

`nuAbortSaveDrag`, `nuAddContentBoxFrames`, `nuAddDragSelected`, `nuAdjustButton`, `nuAdjustDialog`, `nuAlignBottom`, `nuAlignLeft`, `nuAlignRight`, `nuAlignTop`, `nuBindDragEvents`, `nuBlankMultipleValues`, `nuCanMove`, `nuCheckIfMovingFieldToOtherTabAllowed`, `nuCheckIfMovingTabOrderAllowed`, `nuClearFieldsList`, `nuClickGroup`, `nuCloneObjects`, `nuCreateBox`, `nuCreateDrag`, `nuCreateDragOptionsBox`, `nuDeleteSelectedObjects`, `nuDialogInput`, `nuDoNothing`, `nuDragBox`, `nuDragButtonToggleState`, `nuDragCreateButton`, `nuDragCreateCheckbox`, `nuDragCreateSelectBox`, `nuDragCurrentTabNumber`, `nuDragGenerateOptionsControlPanel`, `nuDragGetCurrentTabNumber`, `nuDragGetElementBounds`, `nuDragGetRelatedElements`, `nuDragIsElementInBounds`, `nuDragReport`, `nuDragSelectInIframe`, `nuDragSelected`, `nuDragSetElementPosition`, `nuDragSetElementSize`, `nuDragToggleTabOrder`, `nuFindFieldInState`, `nuFindWithAttr`, `nuGetMinTabOrderInTab`, `nuGetNuDragDialogIframes`, `nuGetTopArea`, `nuGroupDialog`, `nuInitialiseDragState`, `nuLoadReport`, `nuMoveDownOrder`, `nuMoveGroup`, `nuMoveGroupUpDown`, `nuMoveNuDrag`, `nuMoveReportGroup`, `nuMoveSelected`, `nuMoveToolbar`, `nuMoveUpOrder`, `nuMoverAdjustHorClick`, `nuMoverAdjustVerClick`, `nuMoverAlignBottomClick`, `nuMoverAlignLeftClick`, `nuMoverAlignRightClick`, `nuMoverAlignTopClick`, `nuNewObject`, `nuObjectDialog`, `nuOrderedSelectObjects`, `nuPopulateFieldsList`, `nuPopulateTabDropdown`, `nuPutFieldDimensionsIntoState`, `nuRemoveBox`, `nuReportDialog`, `nuResizeDrag`, `nuResizeShortestClick`, `nuResizeTallestClick`, `nuResizeThinestClick`, `nuResizeToHighest`, `nuResizeToLowest`, `nuResizeToThinnest`, `nuResizeToWidest`, `nuResizeWidestClick`, `nuSaveNuDrag`, `nuSelectAllDragObjects`, `nuSelectDialog`, `nuSetDialog`, `nuSetTabOrderDataAttrs`, `nuSetTools`, `nuSetnuScroll`, `nuShowContentBoxFrames`, `nuSortObjAsc`, `nuSortObjects`, `nuSpaceHorizontally`, `nuSpaceVertically`, `nuSpacingNotSupported`, `nuThisContentBox`, `nuThisLabel`, `nuToggleDragLabels`, `nuToggleHiddenObjects`, `nuUnbindDragEvents`, `nuUndo`, `nuUnselectAllDragObjects`, `nuUpdateDragFieldsListbox`, `nuUpdateDragOptionsButtonState`, `nuUpdateDragSelections`, `nuUpdateGroup`, `nuUpdateProperties`, `nuUpdateReport`, `nuUpdateSectionProperty`

**Admin tools, context menus, object properties** (57) – nuBuilder Next has its own globeadmin menus (label menu, Ctrl+Shift shortcuts, Inspect record).

`nuAddAdminButton`, `nuAddAdminButtons`, `nuAddIconToBreadcrumbHolder`, `nuAdminPreInspectRecordJS`, `nuAdminToolsClick`, `nuAdminToolsCreateMenuConfig`, `nuAdminToolsRun`, `nuClosePropertiesMsgDiv`, `nuContextLabelHasClass`, `nuContextMenuAccessText`, `nuContextMenuAddTabPrompt`, `nuContextMenuAddTabPromptCallback`, `nuContextMenuAlignText`, `nuContextMenuBeforeRender`, `nuContextMenuBold`, `nuContextMenuClone`, `nuContextMenuClose`, `nuContextMenuCopyIdToClipboard`, `nuContextMenuCurrentTargetBrowseId`, `nuContextMenuCurrentTargetBrowseIdText`, `nuContextMenuCurrentTargetEditIdText`, `nuContextMenuCurrentTargetId`, `nuContextMenuCurrentTargetUpdateId`, `nuContextMenuGetFormId`, `nuContextMenuGetWordWidth`, `nuContextMenuIdHasAlgin`, `nuContextMenuItemPosition`, `nuContextMenuItemPositionChanged`, `nuContextMenuItemText`, `nuContextMenuLabelPrompt`, `nuContextMenuLabelPromptCallback`, `nuContextMenuObjectPopup`, `nuContextMenuPositionText`, `nuContextMenuShowTabSelector`, `nuContextMenuUpdate`, `nuContextMenuUpdateAccess`, `nuContextMenuUpdateAction`, `nuContextMenuUpdateAlign`, `nuContextMenuUpdateLabel`, `nuContextMenuUpdateObject`, `nuContextMenuUpdateValidation`, `nuContextMenuValidationText`, `nuEditPHP`, `nuFormInfoCopyBrowseSQL`, `nuFormInfoCopyPermalink`, `nuFormInfoCurrentProperties`, `nuInitSetBrowseWidthHelper`, `nuOpenContextMenu`, `nuOpenCurrentFormProperties`, `nuOpenCurrentObjectList`, `nuOpenNuDebug`, `nuOpenPropertiesOnMiddleClick`, `nuPrettyPrintMessage`, `nuRoundNearest`, `nuSetBrowseColumnWidths`, `nuShowFormInfo`, `nuShowObjectTooltip`

**Mobile view** (15) – nuBuilder Next uses a responsive layout instead of a scaled mobile view.

`nuAddTabNavigator`, `nuGetTabNavData`, `nuInitMobileView`, `nuMobileViewGetScaledDocumentWidth`, `nuMobileViewGetTransformScale`, `nuMobileViewSetDimensionsAndScale`, `nuMobileViewSetEndElement`, `nuMobileViewSetTopPosition`, `nuMobileViewTabNavUpdateArrowStates`, `nuMobileViewTabNavUpdateStates`, `nuScrollToTopLeft`, `nuSetMobileView`, `nuTabNavCreateIcon`, `nuTabNavOnClick`, `nuVisibleTabs`

**SQL Builder** (5) – The visual SQL Builder is not part of nuBuilder Next.

`nuAngle`, `nuChangeJoin`, `nuDown`, `nuMove`, `nuUp`

**Reports and Fast Report** (7) – Reports are not supported.

`nuBuildFastReport`, `nuEmailReportAction`, `nuFastObject`, `nuFastReportFormat`, `nuGetReport`, `nuNewFastObject`, `nuRunReportAction`

**nuBuilder's own rendering of forms, browse lists and subforms** (195) – nuBuilder Next renders forms, lists and subforms on the server with its own markup; these functions build or position nuBuilder's DOM.

`nuActionButtonsToggleVisibility`, `nuAddActionButtons`, `nuAddAttributes`, `nuAddBreadcrumb`, `nuAddBreadcrumbs`, `nuAddBrowseAdditionalNavButtons`, `nuAddDataTab`, `nuAddDblClickOpenObjectProperties`, `nuAddEditTabs`, `nuAddFormStyle`, `nuAddHolder`, `nuAddHome`, `nuAddHomeLogout`, `nuAddIconToBreadCrumb2`, `nuAddJSObjectEvents`, `nuAddJavaScript`, `nuAddObjectFunctions`, `nuAddStyle`, `nuAddStyleFromArray`, `nuAddedByLookup`, `nuAlign`, `nuAlignmentStyle`, `nuAllowChanges`, `nuApplyAttributes`, `nuApplyInputTypeSpecificBehaviors`, `nuArrangingObjects`, `nuArrowPressed`, `nuAscendingSortColumn`, `nuAscendingSortNumberColumn`, `nuBreadcrumbTitleWithoutBrowse`, `nuBrowseAdditionalNavButtons`, `nuBrowseBorders`, `nuBrowseCalculateInitialTopOffset`, `nuBrowseCells`, `nuBrowseColumnSize`, `nuBrowseCreateFooter`, `nuBrowseCreateFooterHtml`, `nuBrowseGetFirstRowCellId`, `nuBrowseHandleCellCreation`, `nuBrowseHandleNoDataScenario`, `nuBrowseRow`, `nuBrowseRowsPerPageFilter`, `nuBrowseSetCellAttributes`, `nuBrowseSetCellContentAndEvents`, `nuBrowseStickyColumns`, `nuBrowseTable`, `nuBrowseTableHoverIn`, `nuBrowseTableHoverOut`, `nuBrowseTitle`, `nuBrowseTitleMultiLine`, `nuBrowseUpdateParentDocumentStyles`, `nuBuildEditObjects`, `nuBuildForm`, `nuBuildOptionsList`, `nuBuildSubformDeleteTitle`, `nuBuildSubformTitle`, `nuCONTENTBOX`, `nuCSSPropertySum`, `nuCalendarWeekNumbers`, `nuCalendarWeekStartNumber`, `nuCanArrangeObjects`, `nuChangeDeleteClicked`, `nuChooseEventList`, `nuClickSearchColumn`, `nuClickTab`, `nuConvertToVanillaJSCalendarFormat`, `nuCreateElementWithId`, `nuDRAG`, `nuDebugLastMessages`, `nuDecendingSortColumn`, `nuDecendingSortNumberColumn`, `nuDetach`, `nuDialogHeadersHeight`, `nuDisableBrowseResize`, `nuDownBrowseResize`, `nuDragBrowseColumn`, `nuDragBrowseDown`, `nuDragBrowseMove`, `nuDragBrowseUp`, `nuDragTitleEvents`, `nuEDITOR`, `nuEditTab`, `nuEmptyNoClone`, `nuEndBrowseResize`, `nuEvaluateOnLoadEvents`, `nuFormModification`, `nuFormWH`, `nuGenerateSortSpan`, `nuGetBrowsePaginationInfo`, `nuGetColumWidths`, `nuGetDBColumnLengh`, `nuGetFirstObject`, `nuGetLookupFields`, `nuGetNumberFormat`, `nuGetOptionsList`, `nuGetSearchList`, `nuGetSubformDimensions`, `nuGetSubformRowSize`, `nuGetTitleNew`, `nuGetWordWidth`, `nuHTML`, `nuHideOptionsItemShortcutKeys`, `nuIMAGE`, `nuINPUT`, `nuINPUTCalc`, `nuINPUTCheckbox`, `nuINPUTDisplay`, `nuINPUTInput`, `nuINPUTLookup`, `nuINPUTSetMaxLength`, `nuINPUTSetProperties`, `nuINPUTSetValue`, `nuINPUTfileDatabase`, `nuINPUTfileFileSystem`, `nuINPUTnuAutoNumber`, `nuINPUTnuDate`, `nuINPUTnuNumber`, `nuINPUTnuScroll`, `nuIPUTNuChangeEvent`, `nuInitJSOptions`, `nuInitShowJSErrors`, `nuInternalSelectBrowse`, `nuLabel`, `nuLabelGetValidationClass`, `nuLabelOrPosition`, `nuLookingUp`, `nuLookupFocus`, `nuLookupObject`, `nuModifyHolders`, `nuNewRowObject`, `nuNoDuplicates`, `nuNumberFormat`, `nuObjectPosition`, `nuOnFocus`, `nuOnSubformDeleteClick`, `nuOptions`, `nuOptionsListAction`, `nuParseAttributes`, `nuPopulateLookup`, `nuPopulateLookup3`, `nuPortraitLabelWidth`, `nuPortraitScreen`, `nuPortraitScreenObjDimensions`, `nuRUN`, `nuRUNGetOnClickEvent`, `nuRecordHolderObject`, `nuRecordProperties`, `nuRedefineNuSelectBrowse`, `nuReformat`, `nuResponseDefault`, `nuResponseNoWrap`, `nuResponseWrap`, `nuResponsiveWrap`, `nuRestoreScrollPositions`, `nuSELECT`, `nuSUBFORM`, `nuSUBFORMAddCSS`, `nuSUBFORMScrollDivAddCSS`, `nuSUBFORMnuRECORDAddCSS`, `nuSUBFORMnuTabHolderAddCSS`, `nuSaveScrollPositions`, `nuSearchFieldSetSearchType`, `nuSearchPressed`, `nuSearchableList`, `nuSelectAllTabs`, `nuSelectOnFocus`, `nuSetBody`, `nuSetBrowseHeight`, `nuSetDefaultWindowProperties`, `nuSetFormProperties`, `nuSetMobileView1`, `nuSetObjectBounds`, `nuSetSaveButtonProperties`, `nuSetSearchColumn`, `nuSetStartingTab`, `nuSetTab`, `nuShowBrowsePaginationInfo`, `nuStartingTab`, `nuStickyHeaders`, `nuStopBrowserResize`, `nuSubformHandleArrowKeyEvent`, `nuSubformRearrangeColumns`, `nuSubformSortTop`, `nuTinyMCEOnChangeHandler`, `nuTitleDrag`, `nuTogglePaginationInfo`, `nuTotalHeight`, `nuTotalWidth`, `nuTransformScale`, `nuUnbindDragEvents`, `nuUpdateDebugButtonTitle`, `nuWORD`, `nuWidestTitle`, `nuWindowPosition`, `nuWrapWithForm`

**Subform filter internals** (11) – nuSubformAddFilter() is implemented on its own; these are its nuBuilder internals.

`nuSubformFilterAddSearch`, `nuSubformFilterAddSelect`, `nuSubformFilterAddValues`, `nuSubformFilterCellValue`, `nuSubformFilterCreateSelectOption`, `nuSubformFilterId`, `nuSubformFilterOptionAll`, `nuSubformFilterRows`, `nuSubformFilterSortOptions`, `nuSubformFilterValue`, `nuSubformFiltersAdd`

**Navigation, Ajax, session and window handling** (55) – Part of nuBuilder's single-page client (Ajax calls, breadcrumbs, opener tabs, dialogs) or replaced by nuBuilder Next's own pages and buttons.

`nuAbortSave`, `nuAccessLevelGroup`, `nuAddEditFieldsToHash`, `nuAjaxShowError`, `nuAjax_`, `nuAttachFile`, `nuBackAction`, `nuBindCtrlEvents`, `nuClick`, `nuCloseAfterSave`, `nuDeleteAllAction`, `nuDisplayLoggedInUser`, `nuEditDoCloseAfterSave`, `nuFormatAjaxErrorMessage`, `nuFormatAjaxErrorRemoveJSON`, `nuGetFunctionList`, `nuGetHTML`, `nuGetOpenerById`, `nuImportUsersFromCSV`, `nuIsOpener`, `nuLogin`, `nuNeedToLoginAgain`, `nuOpenFormHelp`, `nuOpenNewBrowserTab`, `nuOpener`, `nuOpenerAppend`, `nuPopEvent`, `nuPopPHP`, `nuPopSQL`, `nuPreview`, `nuPrintCSVExportOptions`, `nuPrintIncludeColumns`, `nuPrintIncludeHiddenColumns`, `nuPrintUseBrowseFormats`, `nuProcessAfterSave`, `nuRemoveOpenerById`, `nuResizeFormDialogCoordinates`, `nuResizeWindow`, `nuRunBackup`, `nuRunPHPAction`, `nuSaveAfterDrag`, `nuSavingProgressMessage`, `nuSelectInParentBody`, `nuSelectInParentContext`, `nuSelectInParentDocument`, `nuSetURLPermaLink`, `nuShowLastUpdatedMessage`, `nuShowMessages`, `nuStopClick`, `nuSubmit`, `nuSystemUpdate`, `nuUpdateData`, `nuUpdateMessage`

### PHP (285)

**Saving, loading and validating records; building forms and browse lists** (83) – nuBuilder Next loads, validates and saves records with its own code (core/form/nurecord.php, nubrowse.php, nurender.php).

`nuAddPrintButtons`, `nuAutoNumbers`, `nuBeforeBrowse`, `nuBeforeEdit`, `nuBreadcrumbDescription`, `nuBreadcrumbDescriptionPart`, `nuBrowseColumns`, `nuBrowseExpandNuEqualsFilter`, `nuBrowseRemoveFieldAlias`, `nuBrowseRows`, `nuBrowseWhereClause`, `nuBuildTabList`, `nuButtons`, `nuCheckAccess`, `nuCheckAccessLevel`, `nuDefaultObject`, `nuDeleteForm`, `nuDisplayErrorAccessDenied`, `nuDisplayErrorRequiredFields`, `nuDuplicate`, `nuEditObjects`, `nuEditedRow`, `nuEvents`, `nuExtractFunctionBody`, `nuFormAccess`, `nuFormAccessList`, `nuFormDimensions`, `nuFormEvents`, `nuFormProperties`, `nuFormatValue`, `nuGatherFormAndSessionData`, `nuGetAllLookupList`, `nuGetAllLookupValues`, `nuGetBrowseWidth`, `nuGetDBTypesSetNullWhenEmpty`, `nuGetEditForm`, `nuGetFile`, `nuGetFormData`, `nuGetFormFinalize`, `nuGetFormModifyObject`, `nuGetFormObject`, `nuGetFormPermission`, `nuGetFormProcessObjects`, `nuGetFormSetBasicProperties`, `nuGetFormStatus`, `nuGetLookupValues`, `nuGetNuDataValue`, `nuGetObjectAccess`, `nuGetOtherLookupValues`, `nuGetSubformRecords`, `nuLogout`, `nuObjectEvents`, `nuOnProcess`, `nuPreloadImages`, `nuProcedureAccessList`, `nuRefineTabList`, `nuReorderBrowse`, `nuReorderTabs`, `nuReportAccessList`, `nuRowsPerPage`, `nuRunCode`, `nuRunDescription`, `nuSetLastLoggedInUser`, `nuSetNuDataValue`, `nuSetupButtons`, `nuSubFormsEdited`, `nuUpdateDatabase`, `nuUpdateDatabaseAddNuLog`, `nuUpdateDatabaseDelete`, `nuUpdateDatabaseExecStatements`, `nuUpdateDatabaseGetUpdateValue`, `nuUpdateDatabaseHasDataModePermission`, `nuUpdateDatabaseIsValidTable`, `nuUpdateDatabaseRunAfterEvents`, `nuUpdateDatabaseRunBeforeDeleteEvent`, `nuUpdateDatabaseRunBeforeSaveEvents`, `nuUpdateDatabaseSave`, `nuUpdateDatabaseSetValues`, `nuValidateForms`, `nuValidateFormsFetchFormObjects`, `nuValidateFormsFetchObject`, `nuValidateFormsRequiredFields`

**Login, sessions, 2FA, IP rules** (53) – nuBuilder Next has its own login, sessions, two-step login and vendor login.

`nu2FAGetStoredTokens`, `nu2FALocalTokenOK`, `nu2FARemoveOldTokens`, `nu2FASafeIPAddr`, `nu2FAStatusPending`, `nu2FAStoreAllTokens`, `nu2FAStoreToken`, `nuAccessLevelInfo`, `nuAuthCheck`, `nuAuthGetElapsedTime`, `nuCheckExistingSession`, `nuCheckGarbageCollector`, `nuCheckGlobeadminLoginRequest`, `nuCheckIsLoginRequest`, `nuCheckUserLoginRequest`, `nuDie`, `nuExpandIPv6`, `nuGet2FASafeIPAddresses`, `nuGet2FAToken`, `nuGet2FATokenSentTime`, `nuGet2FAVerifiedTime`, `nuGetAccessLevelQuery`, `nuGetFormAccessQuery`, `nuGetLoginUserId`, `nuGetPHPIdsQuery`, `nuGetReportIdsQuery`, `nuGetTranslation`, `nuGetVendorURL`, `nuIDTEMP`, `nuIsHTTPS2`, `nuIsValidIPPattern`, `nuLoadNewSession`, `nuLoginSetupGlobeadmin`, `nuLoginSetupNOTGlobeadmin`, `nuMatchesAnyIPPattern`, `nuMatchesIPPattern`, `nuMatchesIPv4Pattern`, `nuMatchesIPv6Pattern`, `nuNormaliseIPv6`, `nuOutput2FATokenToConsole`, `nuPasswordChangeStatusPending`, `nuRedirectToForm`, `nuRunLoginProcedure`, `nuSet2FAToken`, `nuSet2FATokenSentTime`, `nuSet2FAVerified`, `nuSet2FAVerifiedTime`, `nuSetAuthenticated`, `nuSetSafeSessionName`, `nuShow2FAAuthenticationError`, `nuTempAnonReport`, `nuTokenMatches`, `nuUpdateExistingSession`

**Reports, PDF and HTML print** (36) – Reports are not supported; list printing is done by nuBuilder Next's own print and CSV export.

`nuAddCriteriaValues`, `nuBuildCurrencyFormats`, `nuBuildReport`, `nuBuildTempTable`, `nuExecuteQueryAndFetchData`, `nuGetColumnAlias`, `nuGetDebugMessageData`, `nuGetObjectProperties`, `nuGetTotalPages`, `nuIsField`, `nuIsFile`, `nuIsImage`, `nuLowestGroupChange`, `nuMakeSummaryTable`, `nuPdfObject`, `nuPdfSection`, `nuPrintBackground`, `nuPrintField`, `nuPrintImage`, `nuPrintReport`, `nuRemoveFiles`, `nuRemovePageBreak`, `nuReplaceLabelHashVariables`, `nuRunHTML`, `nuRunHTMLCleanup`, `nuRunHTMLDownloadCSV`, `nuRunHTMLGenerateHTMLTable`, `nuRunHTMLGenerateTableData`, `nuRunHTMLGenerateTableHeader`, `nuRunHTMLPrintColumn`, `nuRunReport`, `nuRunReportId`, `nuSavePDF`, `nuSetPixelsToMM`, `nuTTList`, `nuTableToCSV`

**Fast Form / Fast Report builders** (20) – nuBuilder Next has its own "New form" wizard.

`nuBuildFastForm`, `nuBuildFastReport`, `nuFFCreateNewTable`, `nuFFCreateTable`, `nuFFCreatedMessage`, `nuFFError`, `nuFFFormProperties`, `nuFFGetLink`, `nuFFInsertBrowse`, `nuFFInsertForm`, `nuFFInsertObjects`, `nuFFInsertRunButton`, `nuFFInsertTab`, `nuFFIsNewTable`, `nuFFNewTableSQL`, `nuFFNumber`, `nuFFObjectMaxTop`, `nuFFTempCreate`, `nuFFTempInsertSampleObjects`, `nuFFTempUpdate`

**AI calls** (10) – nuBuilder Next's prompt generator only builds prompts; it does not call an AI service.

`nuAIPromptBuildPromptInformation`, `nuAIPromptExtractJSON`, `nuAIPromptGetResponse`, `nuAIPromptGetTableInformation`, `nuAIPromptGetTagsFromPrompt`, `nuAIPromptParseParamArray`, `nuAITestCredentials`, `nuBuildCurlOptions`, `nuGetAIResponse`, `nuResolveAIConfig`

**Installation, update, setup and includes** (40) – nuBuilder Next has its own installer and updater and does not change nuBuilder's system tables.

`nuAddNewSystemTables`, `nuAlterSystemTables`, `nuAppendToSystemTables`, `nuArrayFlatten`, `nuBuildFormSchema`, `nuBuildTableSchema`, `nuBuildViewSchema`, `nuCSSInclude`, `nuCSSIndexInclude`, `nuCleanConfigValue`, `nuConfigScript`, `nuCopySystemTables`, `nuCreateJSONColumns`, `nuDropDatabaseObject`, `nuGetIncludedLanguages`, `nuGetJSActionScreen`, `nuGetJS_login`, `nuGetSupportedLanguagesAsJson`, `nuImportLanguageFiles`, `nuImportNewDB`, `nuInclude`, `nuIncludeConfigPHPFiles`, `nuIncludeFiles`, `nuInstallException`, `nuJSInclude`, `nuJSIndexInclude`, `nuJustNuRecords`, `nuLoadBody`, `nuMergeIncludeFiles`, `nuOtherUpdates`, `nuRemoveNuRecords`, `nuRunSystemUpdate`, `nuSQLImportFileExists`, `nuSetCollation`, `nuSetupImportSQLFile`, `nuSystemList`, `nuUpdateFormSchema`, `nuUpdateSystemRecords`, `nuUpdateTableSchema`, `nuUseUP`

**Files, temp files, uploads** (6) – Files are stored in zzzzsys_file / file fields by nuBuilder Next itself; nuBuilder's temp folder is not used.

`nuCreateFile`, `nuCreateTempFile`, `nuDeleteFiles`, `nuImageList`, `nuSetUploadError`, `nuUploadFile`

**CSV and translation admin tools** (6) – Replaced by nuBuilder Next's CSV import, list CSV export and Translations page.

`nuFromCSV`, `nuImportUsersFromCSV`, `nuToCSV`, `nuTranslateExport`, `nuTranslateGenerateFile`, `nuTranslateWriteSQL`

**Not ported on purpose (security)** (1) – Returns the database password to form code – deliberately left out.

`nuGetDBParams`

**nuBuilder internals (eval, hash list, properties, debug, misc.)** (30) – Internal helpers of nuBuilder's request handling; nuBuilder Next does these things differently (hash variables, properties, debug log, eval of code).

`nuAddToHashCookies`, `nuAddToHashList`, `nuAllowedActivities`, `nuClientTimeZone`, `nuDebugMessageString`, `nuDescribeTableSQL`, `nuDragSave`, `nuEval`, `nuEvalMessage`, `nuEvalSafe`, `nuEvalSafeErrorHandler`, `nuExceptionHandler`, `nuFailIfUnsetHashCookies`, `nuFontList`, `nuFormatList`, `nuFormatVarArgs`, `nuGetDefaultFormats`, `nuGetFormProperties`, `nuGetLastDebugMessages`, `nuGetProperties`, `nuGetSelectType`, `nuGetURLParams`, `nuID_DEV`, `nuPrepareQuery`, `nuQueryExtractQueryComponents`, `nuRunQueryString`, `nuSanitizeSqlQuery`, `nuSendError`, `nuSetGlobalPropertiesJS`, `nuSetHashList`

