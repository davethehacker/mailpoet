define(
  [
    'react',
    'react-router',
    'mailpoet',
    'form/form.jsx'
  ],
  function(
    React,
    Router,
    MailPoet,
    Form
  ) {

    var fields = [
      {
        name: 'email',
        label: 'E-mail',
        type: 'text'
      },
      {
        name: 'first_name',
        label: 'Firstname',
        type: 'text'
      },
      {
        name: 'last_name',
        label: 'Lastname',
        type: 'text'
      },
      {
        name: 'status',
        label: 'Status',
        type: 'select',
        values: {
          'unconfirmed': 'Unconfirmed',
          'subscribed': 'Subscribed',
          'unsubscribed': 'Unsubscribed'
        }
      }
    ];

    var messages = {
      updated: function() {
        MailPoet.Notice.success('Subscriber successfully updated!');
      },
      created: function() {
        MailPoet.Notice.success('Subscriber successfully added!');
      }
    };

    var Link = Router.Link;

    var SubscriberForm = React.createClass({
      mixins: [
        Router.History
      ],
      render: function() {
        return (
          <div>
            <h2 className="title">
              Subscriber <a
                href="javascript:;"
                className="add-new-h2"
                onClick={ this.history.goBack }
              >Back to list</a>
            </h2>

            <Form
              endpoint="subscribers"
              fields={ fields }
              params={ this.props.params }
              messages={ messages }
              onSuccess={ this.history.goBack } />
          </div>
        );
      }
    });

    return SubscriberForm;
  }
);
