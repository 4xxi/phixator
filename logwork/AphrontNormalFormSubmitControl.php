<?php

class AphrontNormalFormSubmitControl extends AphrontFormControl
{
    private $buttons = array();

    public function addCancelButton($href, $label = null)
    {
        if (!$label) {
            $label = pht('Cancel');
        }
        $button = id(new PHUIButtonView())
                    ->setTag('a')
                    ->setHref($href)
                    ->setText($label)
                    ->setColor(PHUIButtonView::GREY);

        $this->addButton($button);

        return $this;
    }

    public function addButton(PHUIButtonView $button)
    {
        $this->buttons[] = $button;

        return $this;
    }

    protected function getCustomControlClass()
    {
        return 'aphront-form-control-submit';
    }

    protected function renderInput()
    {
        $submit_button = null;

        if ($this->getValue()) {
            $submit_button = phutil_tag(
                'input',
                [
                    'type'      => 'submit',
                    'name'      => '__submit__',
                    'value'     => $this->getValue(),
                    'disabled'  => $this->getDisabled() ? 'disabled' : null,
                ]
            );
        }

        return [
            $submit_button,
            $this->buttons,
        ];
    }
}
