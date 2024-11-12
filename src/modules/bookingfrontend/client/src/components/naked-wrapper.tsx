import {FC} from 'react';


const NakedWrapper: FC<any> = (props) => {
    return (
        <div>{props.children}</div>
    );
}

export default NakedWrapper


