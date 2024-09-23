import React, {Component} from 'react';


class SenderForm extends Component {

  static slug = 'divi_sender_form';

  constructor(props) {
    super(props);
    this.rootElement = React.createRef();
  }


  componentDidUpdate(prevProps, prevState, snapshot) {
    this.renderFrom();
  }

  shouldComponentUpdate(nextProps, nextState, nextContext) {
    return this.props.form !== nextProps.form;
  }

  componentDidMount() {
    this.renderFrom();
  }

  renderFrom() {
    const form = this.props.form;

    if (this.rootElement.current.querySelector('div')) {
      this.rootElement.current.querySelector('div').remove();
    }

    if (!form) {
      return;
    }

    const _window = this.rootElement.current.ownerDocument.defaultView;

    setTimeout(() => {
      _window.senderForms.render(form);
    });
  }

  render() {
    const form = this.props.form;

    return (
        <div
            ref={this.rootElement}
            className={'sender-form-field'}
            data-sender-form-id={form}>
          {!form && "Select from"}
        </div>
    );
  }
}

export default SenderForm;
