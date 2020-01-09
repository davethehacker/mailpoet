
export function toggleSidebar(toggleTo) {
  return {
    type: 'TOGGLE_SIDEBAR',
    toggleTo,
  };
}

export function changeFormBlocks(blocks) {
  return {
    type: 'CHANGE_FORM_BLOCKS',
    blocks,
  };
}

export function changeFormName(name) {
  return {
    type: 'CHANGE_FORM_NAME',
    name,
  };
}

export function onCustomFieldDeleteClick() {
  return {
    type: 'CUSTOM_FIELD_DELETE_CLICK',
  };
}

export function onCustomFieldDeleteCancel() {
  return {
    type: 'CUSTOM_FIELD_DELETE_CANCEL',
  };
}

export function deleteCustomFieldStarted() {
  return {
    type: 'DELETE_CUSTOM_FIELD_STARTED',
  };
}

export function deleteCustomFieldDone(customFieldId, clientId) {
  return {
    type: 'DELETE_CUSTOM_FIELD_DONE',
    customFieldId,
    clientId,
  };
}

export function deleteCustomFieldFailed(message = undefined) {
  return {
    type: 'DELETE_CUSTOM_FIELD_FAILED',
    message,
  };
}
export function changeFormStyles(styles) {
  return {
    type: 'CHANGE_FORM_STYLES',
    styles,
  };
}

export function saveCustomFieldDone(customFieldId, response) {
  return {
    type: 'SAVE_CUSTOM_FIELD_DONE',
    customFieldId,
    response,
  };
}

export function saveCustomFieldStarted() {
  return {
    type: 'SAVE_CUSTOM_FIELD_STARTED',
  };
}

export function saveCustomFieldFailed(message = undefined) {
  return {
    type: 'SAVE_CUSTOM_FIELD_FAILED',
    message,
  };
}

export function changeFormSettings(settings) {
  return {
    type: 'CHANGE_FORM_SETTINGS',
    settings,
  };
}

export function saveFormDone(result) {
  return {
    type: 'SAVE_FORM_DONE',
    result,
  };
}

export function saveFormStarted() {
  return {
    type: 'SAVE_FORM_STARTED',
  };
}

export function saveFormFailed(message = undefined) {
  return {
    type: 'SAVE_FORM_FAILED',
    message,
  };
}

export function removeNotice(id) {
  return {
    type: 'REMOVE_NOTICE',
    id,
  };
}

export function switchSidebarTab(id) {
  return {
    type: 'SWITCH_SIDEBAR_TAB',
    id,
  };
}

/**
 * Toggle a panel within the sidebar. Use toggleTo to enforce certain state
 * @param {string} id
 * @param {string|undefined} toggleTo - possible values 'opened', 'closed'
 * @return {{toggleTo: string|undefined, id: string, type: string}}
 */
export function toggleSidebarPanel(id, toggleTo = undefined) {
  return {
    type: 'TOGGLE_SIDEBAR_PANEL',
    id,
    toggleTo,
  };
}

export function* saveForm() {
  yield {
    type: 'SAVE_FORM',
  };
}

export function* saveCustomField(data) {
  yield {
    type: 'SAVE_CUSTOM_FIELD',
    ...data,
  };
}

export function* onCustomFieldDeleteConfirm(customFieldId, clientId) {
  yield {
    type: 'DELETE_CUSTOM_FIELD',
    customFieldId,
    clientId,
  };
}
