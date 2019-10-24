import { registerStore } from '@wordpress/data';
import actions from './actions.jsx';
import createReducer from './reducer.jsx';
import selectors from './selectors.jsx';

const defaultState = {
  sidebarOpened: true,
  formData: window.mailpoet_form_data,
};

const config = {
  reducer: createReducer(defaultState),
  actions,
  selectors,
  controls: {},
  resolvers: {},
};

export default () => (registerStore('mailpoet-form-editor', config));